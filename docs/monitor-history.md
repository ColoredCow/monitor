# Monitor History Operations

This document covers operational workflows for monitor history logging, aggregation, backfilling, and retention.

## Feature Flag

Monitor history UI and payload are controlled by:

```bash
MONITOR_HISTORY_ENABLED=true
```

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

Creates synthetic check logs from current monitor state and aggregates days window.

```bash
php artisan monitor:backfill-check-history --days=30
```

Useful options:

- `--monitor-id=123`
- `--timezone=Asia/Kolkata`
- `--dry-run`

Notes:

- Backfill records include `metadata.source=backfill`.
- Backfill is best-effort and should be treated as approximate history.

### Prune old raw logs

Prunes raw logs older than configured retention while preserving daily aggregates.

```bash
php artisan monitor:prune-check-history
```

Useful options:

- `--older-than-days=180`
- `--dry-run`

## Scheduler

Configured schedules:

- `monitor:aggregate-check-metrics` hourly
- `monitor:prune-check-history` daily at `01:00`

Existing schedules remain unchanged:

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
2. Deploy ingestion listeners/model hooks/services.
3. Enable scheduler entries for aggregate/prune commands.
4. Run backfill in `--dry-run` mode and review expected writes.
5. Run live backfill once approved.
6. Validate monitor detail page:
   - range filters work
   - heatmaps render by check type
   - recent checks and totals are sensible
7. Enable `MONITOR_HISTORY_ENABLED=true` in target environment.
8. Monitor logs for command failures and verify aggregate row growth.

## Troubleshooting

### No heatmap data visible

- Confirm feature flag is enabled.
- Confirm `monitor_check_logs` has data.
- Run `monitor:aggregate-check-metrics --lookback=30`.
- Check selected timezone/range in monitor detail.

### Duplicate entries concern

- `monitor_check_logs` has an `idempotency_key` unique index.
- Verify retries are not mutating check payload fields unexpectedly.

### Domain history missing

- Ensure monitor has `domain_check_enabled=true`.
- Verify `monitor:check-domain-expiration` runs and WHOIS lookup is reachable.

