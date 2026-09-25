# Credit System Design

**Date:** 2026-07-02
**Status:** Approved design, pending implementation plan

## Summary

A monetization feature: organizations hold a credit balance, and every executed
monitor check consumes credits. When the balance reaches zero, all of the
organization's checks pause until a super-admin grants more credits. Credits are
granted manually by super-admins in v1 (after offline payment or as promos); the
ledger design is payment-gateway-ready so self-serve purchases can be layered on
later without schema changes.

## Product decisions

| Decision | Choice |
|---|---|
| Purpose | Monetization / billing (revenue feature) |
| Consumption model | Per check executed |
| Pricing | Flat: every check (uptime, certificate, domain) costs 1 credit, regardless of outcome |
| Exhaustion behavior | Pause all checks immediately at zero balance |
| Acquisition | Super-admin manual grants only (v1) |
| Warnings | Email org admins at runway thresholds, plus paused/resumed notices |
| Org visibility | Balance, projected runway (dashboard card, header chip, monitor-form preview), per-monitor/per-check-type usage breakdown, transaction history |
| Credit expiry | None in v1 |

A failed check costs the same as a successful one — it consumed the same
resources. Checks paused for lack of credits cost nothing (they never execute).

## Data model

### `organizations.credit_balance`

Signed integer column (default 0) on the existing `organizations` table. This is
the live balance, decremented atomically per check:

```sql
UPDATE organizations SET credit_balance = credit_balance - 1 WHERE id = ?
```

Signed on purpose: checks already in flight when the balance crosses zero may
push it a few credits negative. This is accepted behavior, not a bug, and is
never more than one scheduler tick's worth of checks.

### `credit_transactions`

The auditable ledger. Columns:

- `id`
- `organization_id` — FK, cascade on delete
- `type` — string: `grant`, `adjustment`, `usage_debit`
- `amount` — signed integer (positive for grants, negative for debits/corrections)
- `balance_after` — integer snapshot at write time
- `description` — nullable string (grant note)
- `created_by` — nullable FK to `users` (the super-admin; null for system rows)
- `metadata` — nullable JSON (e.g., `{"date": "2026-07-01"}` on usage debits)
- timestamps
- index on (`organization_id`, `created_at`)

One row per super-admin grant/adjustment, plus one system-generated daily
`usage_debit` per org (written by the nightly rollup). Invariant: the sum of an
org's transactions equals its balance as of the last rollup; intra-day drift is
only today's not-yet-rolled-up usage.

`recordUsageDebit` reads the balance under `lockForUpdate` inside a
transaction (hardened during implementation beyond the original draft) so
`balance_after` is consistent with concurrent grants.

### `credit_usage_daily`

Analytics table powering the dashboard breakdown:

- `id`
- `organization_id` — FK, cascade on delete
- `monitor_id` — nullable FK, **null on delete** (preserves org usage history if
  a monitor is hard-deleted by the purge)
- `check_type` — string: `uptime`, `certificate`, `domain`
- `date` — UTC date
- `credits` — unsigned integer counter
- timestamps
- unique on (`organization_id`, `monitor_id`, `check_type`, `date`)

Upserted (+1) on every metered check.

### Config: `config/credits.php`

- `default_grant` — credits granted automatically when a new organization is
  created (default 0). If positive, recorded as a normal `grant` transaction.
- Warning thresholds: runway days for `low` (7) and `critical` (2).

## Metering

`CreditMeteringService::recordCheck(Monitor $monitor, string $checkType)` does
exactly two writes: the atomic balance decrement and the daily-usage upsert.

Call sites (all unconditional — NOT gated behind `monitor-history.enabled`):

- `Monitor::uptimeRequestSucceeded()` / `uptimeRequestFailed()` overrides
  (already exist for check logging)
- The certificate-check equivalents on the Monitor model
- The custom `monitor:check-domain-expiration` command

When a decrement takes the balance from positive to zero or below, the
zero-crossing dispatches the "monitoring paused" notification once (guarded
by the org's warning level so retries and in-flight checks don't spam).

Metering must never break monitoring: `recordCheck()` wraps its writes in
try/catch and logs failures. A transient DB hiccup loses at most a credit's
worth of metering — it must not fail the check run.

**DOWN-monitor rechecks are billed.** The vendor rechecks DOWN monitors every
minute regardless of interval, and each executed recheck costs 1 credit — a
deliberate policy decision (2026-07-04). Runway projections assume monitors
are UP; actual burn during an outage can exceed the projection by up to
interval×.

## Enforcement

Query-level exclusion, no state mutation. The three check commands
(`monitor:check-uptime`, `monitor:check-certificate`,
`monitor:check-domain-expiration`) exclude monitors whose organization has
`credit_balance <= 0` — following the existing precedent of overriding Spatie
commands (see the `monitor:delete` override).

Key property: nothing on the monitor is toggled. `uptime_check_enabled` and all
user configuration stay untouched, so a top-up resumes checking on the next
scheduler tick with zero cleanup.

## Performance

Per-check metering adds two single-row statements (a primary-key `UPDATE` and a
unique-key upsert) to a pipeline that already updates the `monitors` row on
every check — and inserts a `monitor_check_logs` row when history is enabled.
The check loop is network-bound (10 concurrent HTTP checks, 10s timeout per
site), so the added DB time is negligible. Balance decrements all target one
`organizations` row per org, but Guzzle concurrency is HTTP-level only: the
response handlers run sequentially in one PHP process, so decrements are
serialized and row-lock contention is effectively nil.

**Escape hatch at 10–100× scale:** because all metering funnels through
`CreditMeteringService`, the natural optimization — buffer per-org counts in
memory during a command run and flush one decrement per org per run — is a
service-internal change requiring no schema or call-site changes.

## Runway projection

A single runway concept, used by both the UI and the warning emails: projected
runway derived from **current monitor configuration**, not historical usage.
Computed on read, never stored — so there is no cache to invalidate when
monitors change.

`CreditRunwayService` computes an org's projected daily burn with one aggregate
query over its active (enabled, non-soft-deleted) monitors:

- uptime enabled: `1440 / uptime_check_interval_in_minutes` credits/day
  (interval is a string column — cast to int, floor at 1)
- certificate enabled: +1 credit/day
- domain enabled: +1 credit/day

Runway = `credit_balance / projected daily burn`, humanized to the largest
sensible unit: "~3 months", "~7 days", "~18 hours", "less than an hour",
capped at "over a year". Zero burn (no enabled checks) renders as "credits
aren't being consumed" instead of a runway; zero balance renders the paused
state instead.

Adding/deleting a monitor, changing a check interval, or toggling
certificate/domain checks changes the projection on the next render — with
Inertia that is immediately after the form round-trip.

Config-derived (rather than trailing-7-day historical) burn is deliberate: the
dashboard and warning emails can never disagree, it works from day one for new
orgs, and it reacts instantly to config changes — which is exactly when a
warning matters most. Steady-state, the two converge anyway.

## Scheduled jobs

Both registered in `routes/console.php`:

- **`credits:rollup-usage`** — daily at 00:15 UTC. Sums yesterday's
  `credit_usage_daily` per org and writes one `usage_debit` transaction. Does
  not touch the balance (already decremented live) — this is the audit record.
- **`credits:evaluate-warnings`** — daily, after rollup. Computes each org's
  projected runway via `CreditRunwayService` and escalates a warning level
  stored on the organization: `none → low` (≤ 7 days) `→ critical` (≤ 2 days)
  `→ exhausted` (zero; also set live by the zero-crossing). Each escalation
  emails the org's admins once. A grant resets the level; if the org was
  paused, admins receive a "monitoring resumed" email.

Runway-based thresholds scale with org size — no absolute numbers that mean
different things to a 5-monitor org and a 500-monitor org.

## Notifications

Mail to org admins only (members never receive credit emails):

- `CreditBalanceLow` (runway ≤ 7 days)
- `CreditBalanceCritical` (runway ≤ 2 days)
- `MonitoringPaused` (balance hit zero)
- `MonitoringResumed` (grant while paused)

## UI

### Super-admin

- Balance column on the existing Organizations index page.
- Per-organization credits panel: current balance, grant/adjust form (signed
  amount + note; negative allowed for corrections), transaction ledger.
- Authorization via a policy method; `Gate::before` already admits super-admins
  and no org-level role grants it.

### Organization dashboard

- **Balance card** — current balance and projected runway ("1,240,000 credits ·
  lasts ~3 months at current configuration"), with a prominent "Monitoring
  paused — out of credits" banner at zero.
- **Usage breakdown** — last 30 days from `credit_usage_daily`: daily burn
  chart, split by check type, top monitors by consumption.
- **Transaction history** — grants and daily debits.
- Org members (view-only role) can see all of this; only admins get emails.

### Header chip

A compact runway chip in the authenticated layout header (near the org
switcher), e.g. "~3 months". Balance and projected daily burn are shared as
Inertia props, so the chip refreshes on every navigation — including right
after any monitor edit. Turns amber/red as the warning level escalates.

### Monitor form preview

The add/edit monitor form shows the projected impact before saving, computed
client-side from the form's live values (interval, certificate/domain toggles)
against the org's current burn and balance from shared props — e.g.
"This change: 288 → 1,440 credits/day · runway ~3 months → ~3 weeks". No extra
endpoint needed; for a new monitor the "before" burn is zero, for an edit it is
derived from the monitor's persisted settings already loaded into the form.

## Edge cases

- **Soft-deleted monitors/orgs** are already excluded from check runs, so they
  stop burning credits automatically. Balance survives soft-delete and restore.
- **Hard purge** (60-day) cascades `credit_transactions` and
  `credit_usage_daily` away with the organization.
- **Concurrency** — the decrement is one atomic SQL statement; the upsert is
  `ON DUPLICATE KEY UPDATE`; no read-modify-write anywhere.
- **In-flight negative balance** — bounded by one scheduler tick; accepted.
- **Payment-gateway future** — Stripe/Razorpay integration becomes another
  writer of `grant` transactions; no schema or metering changes needed.

## Testing

TDD during implementation. Coverage:

- Metering: each check type decrements balance and upserts the correct daily
  row; works with `monitor-history.enabled` off; failed checks are charged;
  a metering write failure is logged and does not fail the check run.
- Enforcement: zero-balance orgs' monitors are excluded from all three check
  commands; positive-balance orgs unaffected; top-up resumes without touching
  monitor settings.
- Runway projection: daily burn correct across config permutations (intervals,
  toggles, disabled/soft-deleted monitors excluded); humanized output for
  months/days/hours/sub-hour/over-a-year; zero-burn and zero-balance states;
  warnings use the projection.
- Notifications: zero-crossing fires exactly once; escalation and reset-on-grant
  behave; only admins are emailed.
- Ledger integrity: rollup debit equals the day's metered usage; `balance_after`
  snapshots consistent.
- Authorization: only super-admins grant/adjust; org admins and members can view
  their own org's credit data only.

## Out of scope (v1)

- Payment gateway / self-serve purchase
- Recurring plan allowances / auto-refill
- Per-type or per-org pricing (rates are flat; revisit via config later)
- Credit expiry
- Degraded-frequency mode instead of hard pause

## Rollout

Migrating adds `credit_balance = 0` to every existing organization, which
pauses all of their checks (`Monitor::scopeEnabled` gates on balance > 0). The
required deploy step is to run, immediately after `php artisan migrate`:

```
php artisan credits:grant <amount> --all
```

This grants `<amount>` credits to every organization via
`CreditLedgerService::grant()` — never a raw balance update — so the ledger
stays consistent and any org that was paused receives its "monitoring
resumed" email as part of the normal grant flow.

Three environment variables configure the credit system and should be set
before migrating in production:

- `CREDITS_DEFAULT_GRANT` — credits granted automatically when a new
  organization is created (0 disables the auto-grant).
- `CREDITS_WARNING_LOW_DAYS` — projected-runway days remaining that escalates
  an org's warning level to "low".
- `CREDITS_WARNING_CRITICAL_DAYS` — projected-runway days remaining that
  escalates an org's warning level to "critical".
