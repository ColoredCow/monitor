# Organization Deletion (Soft Delete + 60-Day Purge) — Design Spec

**Extends:** [Organization Dashboard](2026-06-25-organization-dashboard-design.md) · **PR:** [#82](https://github.com/ColoredCow/monitor/pull/82) (same PR)
**Date:** 2026-07-02
**Status:** Awaiting approval

## 1. Problem & goal

Organization deletion was out of scope in v1 — the `restrictOnDelete` FKs deliberately block it. This spec adds it: super-admins can delete an organization; the deletion soft-deletes the org and cascades to its monitors, groups, and (conditionally) users; everything remains restorable for **60 days**, after which a scheduled command hard-purges it. Soft-deleted records are invisible to the app and to the background checks, giving access control "for free."

## 2. Decisions

| Topic | Decision |
|---|---|
| Who can delete | **Super-admins only** (gate `manage-organizations`). Org-admins cannot delete their own org. |
| Mechanism | Laravel **`SoftDeletes`** on `Organization`, `Monitor`, `Group`, `User`. The `organization_user` pivot stays hard (no `deleted_at`) — memberships persist for symmetric restore and are FK-cascaded away at purge. |
| User cascade | **Sole-org users only**: soft-delete non-super-admin users whose *only live* membership is the deleted org. Multi-org users keep their account and simply lose this org. Super-admins are never cascaded. |
| Restore | **Super-admin restore UI**: the Organizations page gets a "Deleted" section (org name, deleted date, days until purge, Restore button). Restore resurrects the org and exactly the children deleted *with* it. |
| Purge | Scheduled **`organizations:purge-deleted`** command (daily), hard-deleting orgs trashed ≥ `config('organizations.purge_after_days', 60)` days, with `--dry-run` and `--older-than-days=`. |
| Unique values | **Functional unique indexes** (live-rows-only) for `monitors.url` and `organizations.slug`. **`users.email` keeps its plain unique index** — emails identify people, so collisions resolve by **restore-and-link**, never a second account. |
| Spatie integration | The package-recommended path: `SoftDeletes` on the configured `monitor_model`. No package patches. The base-model CLI escape hatch (`monitor:delete`) gets an app-level override so it soft-deletes. |

## 3. Why this is the package-recommended path (research findings)

Verified against `spatie/laravel-uptime-monitor` **4.5.1** vendor source:

- **`MonitorRepository` is 100 % Eloquent through the configured model** (`config('uptime-monitor.monitor_model')` → our `App\Models\Monitor`; no raw `DB::` anywhere). With `SoftDeletes` on our model, the `SoftDeletingScope` applies to *every* repository read — `getForUptimeCheck`, `getForCertificateCheck`, `getEnabled`, `findByUrl`, `monitor:list`. **Trashed monitors automatically stop being checked, alerted, and listed.** Zero workarounds.
- **Check history survives soft delete.** `monitor_check_logs` / `monitor_daily_check_metrics` cascade FKs fire only on real SQL DELETEs. Soft delete keeps all history; restore re-attaches it; the purge's `forceDelete` cascades it away intentionally.
- **The package's URL-uniqueness saving guard** (`static::saving` → `alreadyExists()`) uses late static binding, so it checks **live rows only** once `SoftDeletes` is on — exactly matching the functional index semantics. No override needed.
- **Three vendor commands hardcode the *base* Spatie model** (not the configured one): `monitor:create`, `monitor:delete`, `monitor:sync-file`. `monitor:delete` would **hard-delete** (destroying history and bypassing the retention window). Nothing in the app invokes them; see §8 for the guardrail.
- **Auth is free** (verified in framework source): the Eloquent user provider applies the soft-delete scope to *all* lookups — a trashed user cannot log in, remember-me dies, existing sessions are logged out on the next request, and password reset refuses them. Relationship queries, `CurrentOrganization::resolveFor`, the org switcher, and the `{monitor}`/`{group}`/`{user}` route bindings all auto-exclude trashed rows.

## 4. Schema changes

1. **Migration A — `deleted_at`:** `$table->softDeletes()` (indexed) on `organizations`, `users`, `monitors`, `groups`.
2. **Migration B — functional unique indexes** (MySQL ≥ 8.0.13; local verified 8.0.19, **prod version must be confirmed before merge**):
   - `monitors`: drop `monitors_url_unique`; add ``UNIQUE INDEX monitors_url_active_unique ((IF(deleted_at IS NULL, url, NULL)))``
   - `organizations`: same treatment for `slug`.
   - Trashed rows index as `NULL` (unlimited), live rows stay globally unique. Raw `DB::statement` (Blueprint can't express this); tests run on real MySQL so it's covered.
   - `users.email`: **unchanged** (plain unique).

## 5. `OrganizationDeletionService` (the only cascade authority)

Explicit service in `app/Services/` — no observers, no cascade packages (house style: explicit over magic). All three operations are transactional and idempotent.

**`delete(Organization $org)`** — one shared `$ts = now()`:
1. Bulk-update `deleted_at = $ts` on the org's monitors and groups.
2. Soft-delete (same `$ts`) users where: not super-admin, member of this org, and **no other live** organization membership.
3. Soft-delete the org itself with `$ts`. Pivot rows untouched.

The **shared timestamp is the cascade marker**: children trashed *before* the org deletion (individually deleted monitors, etc.) have a different `deleted_at` and are never resurrected by restore.

**`restore(Organization $org)`**:
1. **Slug collision check**: if a *live* org now holds this slug → abort with a friendly error (functional index would reject it anyway; we fail before touching data).
2. Restore the org; restore monitors/groups/users `onlyTrashed()->where('deleted_at', $ts)`.
3. **Per-monitor URL collision**: any monitor whose URL is now held by a live monitor is **skipped and reported** (stays trashed, purged on schedule). Users already restored-and-linked elsewhere (§7) are live and skipped naturally by the timestamp match.

**`purge(Organization $org)`** — FK-safe, two phases:
1. *Outside* the transaction (org already invisible): chunked deletion of `monitor_check_logs` / `monitor_daily_check_metrics` for the org's monitors (`limit()`-loop, never offset-chunking) — avoids one giant implicit cascade holding locks.
2. Short transaction, children→parent per the FK map: `forceDelete` monitors → groups → still-trashed sole-org users → the org (pivot rows FK-cascade). All lookups `withTrashed()` — trashed rows still trip `RESTRICT` FKs.

## 6. Purge command & scheduling

- `organizations:purge-deleted {--older-than-days=} {--dry-run}` — mirrors `PruneMonitorCheckHistory`'s shape; retention from `config('organizations.purge_after_days', 60)` (new `config/organizations.php`); iterates `Organization::onlyTrashed()->where('deleted_at', '<=', $cutoff)` and calls `purge()` per org; `--dry-run` prints counts.
- Scheduled in `routes/console.php`: `->dailyAt('02:30')->withoutOverlapping()`.
- **Not** `Prunable`: `model:prune` prunes models independently with no cross-model ordering — it would `forceDelete` the org while `RESTRICT` FKs still point at it. A dedicated command owns the ordering. (Deliberate single purge path; no `Prunable` traits anywhere.)

## 7. Controller / UX changes

- **`OrganizationsController@destroy`** (new route): gate `manage-organizations`; confirm dialog in UI ("soft-deletes X monitors, Y groups, Z users; restorable for 60 days"); calls `service->delete()`; redirects to index. If the deleted org was the operator's active org, the middleware auto-falls back next request (already handled).
- **`OrganizationsController@restore`** (new route, `POST /organizations/{id}/restore` resolving `withTrashed()`): gate `manage-organizations`; calls `service->restore()`; surfaces skipped-monitor report via flash.
- **Organizations Index**: existing list untouched; adds a "Deleted" section (`onlyTrashed`, `deleted_at`, days-until-purge, Restore button) — only rendered when trashed orgs exist.
- **Delete buttons** on org rows (super-admin only, with `window.confirm`).
- **`UsersController@store` + `OrganizationsController@store` (email restore-and-link):** replace `firstOrNew(['email'])` with `withTrashed()->firstOrNew(['email'])`; if the found account is trashed → `restore()` it, then attach membership (existing name/password kept, exactly like today's link-only semantics). A person re-added during the window gets their account back, not a duplicate.
- **`UserSeeder`**: `withTrashed()` on its `updateOrCreate` lookup (defensive).
- **`MonitorRequest`**: add `Rule::unique('monitors', 'url')->withoutTrashed()` (ignore current monitor on update) so a live-URL duplicate is a validation error, not the package's `CannotSaveMonitor` exception.
- **Group deletion behavior guard:** today deleting a group with monitors is blocked by the FK; soft delete would silently succeed and its monitors would vanish from the dashboard's bucket logic. `GroupsController@destroy` gets an explicit "group still has monitors" validation error, preserving current UX (the UI already disables the button).

## 8. Spatie CLI guardrail

`monitor:delete` is rebound in `AppServiceProvider` to an app-level command with the same signature that resolves through `config('uptime-monitor.monitor_model')` and therefore **soft-deletes**. `monitor:create` / `monitor:sync-file` remain vendor-stock but are documented (config comment) as bypassing org assignment and soft-delete — they were already org-unaware before this change; nothing in the app or scheduler calls any of the three.

## 9. Behavior notes (no code change needed)

- **Members of a deleted org:** multi-org users silently fall back to their next org on the following request; sole-org users are trashed → logged out on next request → login refused. Session revocation is *next-request*, not instant (file session driver); acceptable for v1.
- **Aggregator:** `MonitorDailyCheckMetricsAggregator` reads `monitor_check_logs` without joining monitors, so it re-aggregates a trashed monitor's final days within its 7-day lookback, then self-terminates. Harmless.
- **In-flight queued jobs** that serialized a `User`/`Monitor` restore models *without* global scopes and may briefly act on trashed rows; the app currently queues nothing user-scoped. Noted for the future.

## 10. Testing (TDD, real MySQL — FK ordering genuinely enforced)

- **Cascade:** org delete soft-deletes monitors/groups/sole-org users; multi-org users and super-admins never cascaded; pivot rows survive.
- **Access:** trashed user login refused + active session dies next request; deleted org vanishes from switcher/binding/`resolveFor`; member of deleted org lands on fallback org / no-org page.
- **Spatie regression:** trashed monitors excluded from `MonitorRepository::getForUptimeCheck` / `getEnabled` / `findByUrl`; check logs untouched by soft delete.
- **Restore:** shared-timestamp precision (individually-deleted monitor stays trashed — `travel(1)->minute()` between deletes); slug collision aborts; URL collision skips + reports; restored users regain login.
- **Unique semantics:** re-create same URL/slug during window succeeds (functional index); re-add trashed email restores-and-links (no duplicate row).
- **Purge:** `travel(59)` no-op / `travel(61)` purges (models missing, pivot rows gone, logs gone); FK order (would fail loudly on real MySQL if wrong); `--dry-run` mutates nothing; idempotent re-run.
- **Command override:** `monitor:delete` soft-deletes.

## 11. Out of scope

- Per-monitor/per-user restore UI (restore is org-level; individual records via artisan/tinker).
- Org-admin self-service deletion.
- Instant session revocation (requires database session driver).
- Backups/exports before purge.

## 12. Rollout prerequisites

- **Confirm production MySQL ≥ 8.0.13** (functional indexes). Local: 8.0.19 ✓.
- Deploy = migrations + scheduler picks up the purge command automatically.
