# Implementation Plan: Monitor History (Issue #19)

Issue: https://github.com/ColoredCow/monitor/issues/19

## 1. Context Snapshot (Current Codebase)

- Backend is Laravel 12 + Inertia React.
- Monitors use `spatie/laravel-uptime-monitor` (`4.5.1`) with custom `App\Models\Monitor`.
- Current persisted monitor state is latest-only (`monitors` table fields like `uptime_status`, `uptime_last_check_date`, `domain_expires_at`).
- Scheduled checks currently run via:
  - `monitor:check-uptime` every minute
  - `monitor:check-certificate` daily
  - `monitor:check-domain-expiration` daily
- Domain expiration check updates monitor and sends threshold-based notifications, but does not persist check history.
- A monitor detail route exists (`MonitorsController@show`), but `resources/js/Pages/Monitors/Show.jsx` is missing.

## 2. Goals and Non-Goals

## Goals

1. Log every check execution (uptime, domain, and certificate) with enough detail for history/auditing.
2. Show monitor history in a detailed view with daily calendar heatmaps.
3. Provide daily aggregated metrics to power heatmaps and tooltips.
4. Support totals by duration (all-time, month, week, custom range).
5. Keep design extensible for future check types.

## Non-Goals (for this issue)

1. Replacing the existing monitor cards/dashboard design.
2. Building real-time streaming updates.
3. Retrofitting historical data beyond feasible backfill from existing `monitors` table fields.

## 3. High-Level Design

## Data Layer

Create two new tables:

1. `monitor_check_logs` (raw immutable event/check rows)
2. `monitor_daily_check_metrics` (pre-aggregated per monitor/day/check type)

`monitor_check_logs` columns:

- `id`
- `monitor_id` (FK, indexed)
- `check_type` (`uptime`, `domain`, `certificate`)
- `status` (`success`, `warning`, `failed`, `unknown`)
- `checked_at` (UTC datetime, indexed)
- `response_time_ms` (nullable, primarily for uptime)
- `message` (nullable summary)
- `failure_reason` (nullable)
- `metadata` (json for flexible attributes)
- `created_at`, `updated_at`

`monitor_daily_check_metrics` columns:

- `id`
- `monitor_id` (FK)
- `check_type`
- `date` (UTC date)
- `timezone` (string, default app timezone)
- `total_checks`
- `successful_checks`
- `warning_checks`
- `failed_checks`
- `success_ratio` (decimal)
- `worst_status` (`success` < `warning` < `failed`)
- `avg_response_time_ms` (nullable)
- `p95_response_time_ms` (nullable)
- `computed_at`
- `created_at`, `updated_at`
- unique key: (`monitor_id`, `check_type`, `date`, `timezone`)

Recommended indexes:

- `monitor_check_logs`: (`monitor_id`, `check_type`, `checked_at`), (`checked_at`)
- `monitor_daily_check_metrics`: (`monitor_id`, `check_type`, `date`)

## Logging Flow by Check Type

1. Uptime checks:
- Do not rely only on Spatie events because `UptimeCheckFailed` is threshold-based, not emitted on every failure.
- Add logging at model-method level by overriding methods in `App\Models\Monitor` used in each check path (`uptimeRequestSucceeded`, `uptimeRequestFailed` or equivalent success/failure methods) and writing one row per check attempt.

2. Certificate checks:
- Use listeners for `CertificateCheckSucceeded` and `CertificateCheckFailed` (emitted each run) to log rows.

3. Domain checks:
- Refactor `DomainService::verifyDomainExpiration()` (or command flow) to return structured result DTO/array (`status`, `days_until_expiry`, `expiration_date`, `reason`) and always log an attempt when domain check is enabled.

## Aggregation Strategy

- Add service/command `monitor:aggregate-check-metrics` to aggregate logs into daily metrics.
- Run incremental aggregation (e.g., for today + last N days to account for delayed jobs).
- Optionally aggregate on-write for same-day row updates, but initial version should keep deterministic scheduled aggregation.

## API / Controller

Extend monitor detail endpoint to provide:

- Monitor base data
- Date-range filters (`from`, `to`, `preset`, `timezone`)
- Daily aggregates grouped by check type
- Total counts by range (all-time and selected range)
- Recent raw checks (paginated)

Suggested endpoint shape:

- Keep `monitors.show` Inertia route
- Add a dedicated history payload in `MonitorsController@show`
- Add optional lightweight JSON endpoint later if partial refresh is needed

## Frontend

Create `resources/js/Pages/Monitors/Show.jsx` and render:

1. Header with monitor identity and current status badges.
2. Summary blocks: total checks, success %, failed checks, selected-range stats.
3. Separate heatmaps per check type (uptime and domain required; certificate optional but recommended).
4. Day hover tooltip containing:
- Date
- Total checks
- Success/warning/failure counts
- Success ratio
- Domain-specific detail (e.g., nearest expiry days) where relevant
5. Range filters (all-time, 30 days, 7 days, custom).
6. Recent checks table with type, timestamp, status, message/failure reason.

Color mapping recommendation:

- `0 checks`: neutral gray
- `high success`: green shades
- `mixed/warning`: yellow/orange
- `high failures`: red

## 4. Phase-Wise Plan

## Phase 0: Foundation and Safety

Deliverables:

1. Confirm/fix monitor detail page wiring (`Monitors/Show.jsx`) so route renders correctly.
2. Add a shared status mapping enum/helper for check severity and UI color classes.
3. Add feature flag/config switch if rollout needs controlled enablement.

Acceptance criteria:

1. `monitors.show` route resolves without missing-page errors.
2. No regression to existing index/create/edit monitor flows.

## Phase 1: Storage and Domain Model

Deliverables:

1. Migrations for `monitor_check_logs` and `monitor_daily_check_metrics`.
2. Eloquent models: `MonitorCheckLog`, `MonitorDailyCheckMetric`.
3. Relations from `Monitor` model.
4. Query scopes for filtering by type/date/status.

Acceptance criteria:

1. Migrations run cleanly up/down.
2. Schema includes required indexes and unique constraints.

## Phase 2: Check Logging Ingestion

Deliverables:

1. Uptime logging implementation at monitor model level (one log per check attempt).
2. Certificate listeners for success/failure logging.
3. Domain check result normalization + logging per run.
4. Guardrails for duplicate logs (idempotency key if command retried quickly).

Acceptance criteria:

1. Running each check command produces logs with correct `check_type`, `status`, and timestamp.
2. Disabled check types do not generate logs.

## Phase 3: Aggregation and Data Access

Deliverables:

1. Aggregator service + command (incremental daily computation).
2. Scheduler entry for periodic aggregation.
3. `MonitorsController@show` updated to expose:
- daily heatmap points
- totals for chosen duration
- recent check records

Acceptance criteria:

1. Aggregated daily rows match raw logs for sampled dates.
2. Query performance remains acceptable for monitors with large history.

## Phase 4: Monitor Detail UI

Deliverables:

1. New `Monitors/Show.jsx` page.
2. Heatmap component reusable by check type.
3. Tooltip component for daily metrics.
4. Date range filter controls.
5. Recent checks list/table.

Acceptance criteria:

1. Uptime and domain heatmaps render correctly for empty and populated states.
2. Tooltip content reflects backend data accurately.
3. Mobile and desktop layouts remain usable.

## Phase 5: Backfill, QA, and Rollout

Deliverables:

1. Optional backfill command for historical approximation (if needed).
2. Operational docs (`docs/`) for retention policy and troubleshooting.
3. Rollout checklist and monitoring dashboards/logging validation.

Acceptance criteria:

1. Production rollout does not interrupt scheduled checks.
2. Team can verify data correctness with defined QA script.

## 5. Edge Cases and Handling

1. Monitor has uptime enabled but never checked yet.
- Show neutral cells and `0 checks` tooltip text.

2. Domain WHOIS lookup fails/intermittent network errors.
- Log as `failed` with reason in `failure_reason`; do not silently skip.

3. Domain check disabled for monitor.
- Do not log domain checks; show domain heatmap with “disabled” state.

4. Certificate check disabled or non-HTTPS URL.
- Skip or mark section as not applicable.

5. Timezone boundaries (UTC storage vs user-local date buckets).
- Aggregate using explicit timezone input; default app timezone; avoid date drift.

6. Consecutive retries or duplicate command execution.
- Add idempotency strategy (`monitor_id + check_type + checked_at rounded + status hash`) or de-dupe job.

7. Large data volume (every-minute checks over months).
- Use indexes, paginated raw history, and pre-aggregated daily table.
- Define retention/archive policy (e.g., keep raw 90/180 days, keep daily aggregates long-term).

8. Monitor deleted.
- Decide cascade policy: either cascade delete logs or soft-delete monitor and preserve history.

9. Missing historical data before rollout.
- UI should explicitly show “history starts on <rollout date>”.

10. Partial command failures.
- Log partial completion and continue monitor loop; avoid all-or-nothing write behavior.

## 6. Test Plan (Detailed)

## Unit Tests

1. Status normalization helper maps raw outcomes to `success/warning/failed/unknown` correctly.
2. Domain result mapper handles:
- valid expiration date
- expired domain
- WHOIS failure
- malformed URL

3. Aggregation calculator computes totals and `success_ratio` correctly.

## Feature Tests (Laravel)

1. `monitor:check-uptime` generates one raw log per monitor run.
2. Uptime failure path logs failure reason.
3. `monitor:check-certificate` logs success/failure for HTTPS monitors.
4. `monitor:check-domain-expiration` logs attempts and statuses.
5. Aggregator command creates/updates correct daily metric row.
6. `MonitorsController@show` returns expected payload shape for:
- no logs
- mixed logs
- filtered date ranges

7. Authorization: unauthenticated users cannot access monitor detail history.

## Frontend/Integration Tests

1. Heatmap renders correct number of cells for selected range.
2. Tooltip shows correct metrics on hover.
3. Empty state and disabled check-type state rendering.
4. Range switch updates summary totals and heatmap cells.

## Manual QA Scenarios

1. Create monitor with uptime+domain enabled; run commands; verify both heatmaps.
2. Simulate downtime URL and verify red cells + failure reasons.
3. Disable domain check and verify domain panel behavior.
4. Compare sample raw logs vs daily aggregated totals for correctness.
5. Verify page load time with high-volume synthetic data.

## 7. Ideas and Optional Enhancements

1. CSV export for monitor check history.
2. Incident markers for contiguous downtime windows.
3. Alert annotations on heatmap (notification sent days).
4. React Query-powered async range updates if payload grows.
5. Server-side caching for aggregate queries by `(monitor_id, range, timezone)`.

## 8. Rollout Checklist

1. Deploy migrations.
2. Deploy logging changes.
3. Enable aggregation scheduler.
4. Validate first-day ingestion and aggregate counts.
5. Announce known limitation: historical data starts from rollout date unless backfilled.

## 9. Definition of Done

1. Every executed uptime/domain/certificate check creates traceable log entries.
2. Monitor detail page shows separate uptime and domain daily heatmaps with hover metrics.
3. Users can inspect total checks for all-time and selected durations.
4. Tests cover ingestion, aggregation, and detail payload behavior.
5. Performance remains acceptable for realistic monitor volumes.
