# Monitor History Operations

This document covers operational workflows for monitor history logging, aggregation, backfilling, and retention.

## Feature Flag

The entire monitor-history feature is controlled by a single flag (default **off**):

```bash
MONITOR_HISTORY_ENABLED=true
```

When the flag is **off**, the feature is a true no-op:

- Per-check ingestion (uptime/domain/certificate hooks) writes **no** rows to
  `monitor_check_logs`, so there is no added write load.
- The scheduled `monitor:aggregate-check-metrics` and `monitor:prune-check-history`
  jobs are skipped entirely (nothing is aggregated or pruned).
- The monitor detail page returns no history payload.

Core uptime/certificate/domain checking and domain-expiry notifications are
**not** affected by this flag — they always run.

Operator commands (`monitor:backfill-check-history`, `monitor:aggregate-check-metrics`,
`monitor:prune-check-history`) can still be run manually regardless of the flag, so
history can be pre-staged before the feature is switched on.

## Timezone

Daily metrics are bucketed by a single server-side timezone, and the monitor
detail page reads them back under that **same** timezone — the client/browser
timezone is intentionally not used, otherwise the heatmap would query buckets
that were never written and render empty.

```bash
# Optional. Defaults to APP_TIMEZONE when empty.
MONITOR_HISTORY_TIMEZONE=Asia/Kolkata
```

If you change this value, re-run aggregation so metrics exist under the new
timezone (`monitor:aggregate-check-metrics --from=... --to=...`); buckets written
under the previous timezone will no longer be read by the detail page.

## Commands

### Aggregate daily metrics

Aggregates raw check logs (`monitor_check_logs`) into daily buckets (`monitor_daily_check_metrics`).

```bash
php artisan monitor:aggregate-check-metrics
```

Useful options:

- `--lookback=7`
- `--from=2026-01-01 --to=2026-01-31`
- `--timezone=Asia/Kolkata`

### Backfill synthetic logs (one-time bootstrap)

Seeds **one** synthetic check log per enabled check type from each monitor's
*current* state, then aggregates the window into daily metrics.

```bash
php artisan monitor:backfill-check-history --days=30
```

Useful options:

- `--monitor-id=123`
- `--timezone=Asia/Kolkata`
- `--dry-run`

Notes:

- Backfill records include `metadata.source=backfill`.
- This is a **single current-state snapshot per check type**, not a reconstruction
  of real per-day history (that data does not exist). It exists only so a monitor
  is not completely empty before real checks start accumulating. `--days` sizes the
  aggregation window that rolls the snapshot up; it does not fabricate per-day rows.
- Real history begins accumulating from the moment the feature flag is switched on.

### Prune old raw logs

Prunes raw logs older than configured retention while preserving daily aggregates.

```bash
php artisan monitor:prune-check-history
```

Useful options:

- `--older-than-days=180`
- `--dry-run`

## Scheduler

History maintenance schedules (only run while `MONITOR_HISTORY_ENABLED=true`, and
guarded with `withoutOverlapping()` so a slow run cannot stack on the next tick):

- `monitor:aggregate-check-metrics` hourly
- `monitor:prune-check-history` daily at `01:00`

Existing core schedules remain unchanged and always run:

- `monitor:check-uptime` every minute
- `monitor:check-certificate` daily
- `monitor:check-domain-expiration` daily

## Retention

Raw history retention is configurable in:

- `config/monitor-history.php` -> `raw_log_retention_days`

Default:

- `180` days for `monitor_check_logs`
- `monitor_daily_check_metrics` are retained for long-term trend analysis

## Rollout Checklist

1. Deploy migrations for history tables and idempotency key.
2. Deploy the code (ingestion hooks, scheduler entries, services). All of it stays
   dormant while `MONITOR_HISTORY_ENABLED` is off.
3. (Optional) Pre-stage approximate history while the flag is still off:
   - Run `monitor:backfill-check-history --dry-run` and review expected writes.
   - Run the live backfill once approved.
4. Enable `MONITOR_HISTORY_ENABLED=true` in the target environment. This activates,
   together: per-check ingestion, the hourly aggregate + daily prune schedules, and
   the detail-page payload. Real history accumulates from this point forward.
5. Validate the monitor detail page:
   - range filters work
   - heatmaps render by check type
   - recent checks and totals are sensible
6. Monitor logs for command failures and verify aggregate row growth.

## Troubleshooting

### No heatmap data visible

- Confirm `MONITOR_HISTORY_ENABLED=true`. Ingestion only writes logs while the flag
  is on, so a freshly enabled feature has no history until checks run (or a backfill
  is performed).
- Confirm `monitor_check_logs` has data.
- Run `monitor:aggregate-check-metrics --lookback=30`.
- Check selected timezone/range in monitor detail.

### Duplicate entries concern

- `monitor_check_logs` has an `idempotency_key` unique index.
- The key is derived from `monitor_id + check_type + status + checked_at` (rounded to
  the second), so a quick command retry of the same check collapses onto the existing
  row. Message/metadata are not part of the key.

### Domain history missing

- Ensure monitor has `domain_check_enabled=true`.
- Verify `monitor:check-domain-expiration` runs and WHOIS lookup is reachable.

