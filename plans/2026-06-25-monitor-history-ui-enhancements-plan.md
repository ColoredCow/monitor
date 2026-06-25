# Monitor History UI Enhancements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure the monitor detail page's Monitor History UI — decouple the heatmaps from the filters into a full-calendar-year view with year navigation, add a per-check "today" bar, tabbed+paginated recent checks, styled tooltips, per-metric legends, readable dates, and a set of high-value enhancements that surface data the backend already computes.

**Architecture:** Backend (`MonitorsController@show`) splits its Inertia payload into independently-reloadable props (`graph`, `filters`, `summary`, `recentChecks`) so year-nav, filter changes, tab switches, and pagination each refresh only their slice via Inertia partial visits. Frontend extracts pure date/calendar/status logic into unit-tested utilities, rebuilds the heatmap as a responsive full-year grid, and composes focused components. The feature stays behind the existing `config('monitor-history.enabled')` flag.

**Tech Stack:** Laravel 12, Inertia v2 (`@inertiajs/react` ^2.3.8), React 19, Tailwind v4, `@headlessui/react`, `@heroicons/react`, PHPUnit 11 (MySQL `monitor_test`), Vitest (added Phase 1, for pure JS utils only).

## Global Constraints

- **Branch:** create `feat/monitor-history-ui` off `main` before any task (we are currently on `main`).
- **Feature flag:** all history UI/payload remains gated by `config('monitor-history.enabled')`; do not change gating. When disabled, `graph`/`filters`/`summary`/`recentChecks` are all `null`.
- **Timezone:** every date/time is resolved and formatted in the server timezone `config('monitor-history.timezone') ?: config('app.timezone','UTC')` — never the browser timezone. JS formatters MUST use `timeZone: 'UTC'` on already-tz-shifted ISO strings.
- **Status palette (unchanged), single source of truth `resources/js/Utils/checkStatusSeverity.js`:** healthy `green-300/500/700`, warning `yellow-300`/`orange-400`, failed `red-300/500/700`, no-checks `gray-100`, unknown `gray-300`. Brand accent indigo/purple (`purple-600`).
- **Numbers:** `tabular-nums` on all counts, ratios, latencies, timestamps.
- **Motion:** transitions 150ms ease-out; every transition carries `motion-reduce:transition-none motion-reduce:transform-none`; gate JS animation behind `matchMedia('(prefers-reduced-motion: reduce)')`.
- **Accessibility:** contrast ≥4.5:1 (chrome text min `gray-600`); never color-alone (text/icon/aria); `focus-visible` rings on all interactive elements; interactive cells keyboard-reachable.
- **Locked decisions (approved):** numbered pager, page size **25**; tabs **Uptime + Domain only**; date format **`27 Mar 2026`**; **Back inline-left** of title; **Certificate deferred** — omit certificate from `graph.check_types`, the today-bar, tabs, and legends (do not render cert sections); include **all Tier-1 enhancements** (E1–E9), defer Tier-2.
- **Backend tests:** PHPUnit against MySQL `monitor_test`. Before running: `php artisan config:clear` and ensure the DB exists (`mysql -u root -e "CREATE DATABASE IF NOT EXISTS monitor_test;"`). Pattern: feature tests on `monitors.show` asserting Inertia props via `assertInertia`. Run a single test with `php artisan test --filter <TestName>`.
- **Frontend pure utils:** TDD with Vitest (`npm run test:js`). **Frontend components:** no DOM test runner is added — verify with `npm run build` (must succeed) plus the backend feature tests that assert payload shape. This is a deliberate platform constraint, not skipped testing.
- **Per task:** run `vendor/bin/pint --dirty` for PHP changes; `npm run build` for JS component changes; commit at the end of each task.
- **Inertia partial-visit contract (used by every interactive history control):** match the existing pattern in `Show.jsx`:
  ```js
  router.get(route('monitors.show', monitor.id), params, {
    only: [/* props to refresh */], preserveState: true, preserveScroll: true, replace: true,
  })
  ```
  `params` = the full current history param set `{ year, preset, from, to, recent_type, recent_page }` merged with the control's change (use the helper `buildHistoryParams(current, overrides)` from Task 3.x). Targets per control:
  - **Year nav** → `only: ['graph']`, override `{ year }`.
  - **Preset / Apply filter** → `only: ['filters','summary','recentChecks']`, override `{ preset|from|to, recent_page: 1 }`.
  - **Tab change** → `only: ['recentChecks']`, override `{ recent_type, recent_page: 1 }`.
  - **Page change** → `only: ['recentChecks']`, override `{ recent_page }`.
  - Each control sets a local pending flag on `onStart`/`onFinish` → disable + `aria-busy` + skeleton if >300ms.

---

## Interfaces & Contracts (authoritative — every task consumes these names verbatim)

### Inertia payload from `MonitorsController@show` (when flag enabled)

```
monitor:  // existing model; fields the UI reads:
  name, url, raw_url, uptime_status, uptime_last_check_date,
  uptime_check_failure_reason, domain_expires_at,
  uptime_check_enabled, domain_check_enabled, certificate_check_enabled
features: { monitorHistory: bool }   // shared (HandleInertiaRequests)

graph:    // NOT filter-driven; driven by ?year
  year: int
  available_years: int[]             // [earliestDataYear .. currentYear], ascending
  timezone: string
  check_types: [{ type: 'uptime'|'domain', enabled: bool }]   // cert omitted
  series: {
    <type>: {
      summary: { total_checks:int, success_ratio:float,
                 status_totals: { success:int, warning:int, failed:int, unknown:int } },
      daily_metrics: [ { date:'YYYY-MM-DD', total_checks:int, successful_checks:int,
                         warning_checks:int, failed_checks:int, success_ratio:float,
                         worst_status:string, avg_response_time_ms:int|null,
                         p95_response_time_ms:int|null } ],   // only days that have data
      today_checks: [ { id:int, checked_at:'YYYY-MM-DD HH:mm:ss', status:string,
                        message:string|null, failure_reason:string|null,
                        response_time_ms:int|null } ],         // today only, cap 200, newest first
    }
  }

filters:  { preset:string, from:'YYYY-MM-DD', to:'YYYY-MM-DD', timezone:string }

summary:  // filter-driven (selected_range) + all_time
  all_time:      { total_checks, success_ratio, status_totals, by_type }   // buildSummary() shape
  selected_range:{ total_checks, success_ratio, status_totals, by_type }
  first_checked_at: 'YYYY-MM-DD HH:mm:ss' | null

recentChecks: // filter-driven + tab + paginated
  type: 'uptime'|'domain'
  data: [ { id, check_type, status, checked_at:'YYYY-MM-DD HH:mm:ss',
            message, failure_reason, response_time_ms } ]
  pagination: { current_page:int, last_page:int, per_page:int, total:int }
```
When the flag is disabled, `graph`/`filters`/`summary`/`recentChecks` are `null`.
`buildSummary($query)` already returns `{ total_checks, status_totals{success,warning,failed,unknown}, by_type{<type>:{total_checks,status_totals,success_ratio}}, success_ratio }` — reuse as-is.

### Backend helper signatures (private methods on `MonitorsController`)

```php
protected function resolveGraphYear(Request $request, array $availableYears): int   // ?year, default current year (tz), clamped into availableYears
protected function availableYears(Monitor $monitor, string $timezone): array         // [minYear..currentYear] ascending; [currentYear] if no data
protected function graphCheckTypes(Monitor $monitor): array                          // [{type:'uptime',enabled},{type:'domain',enabled}] — cert omitted
protected function buildGraphPayload(Monitor $monitor, int $year, string $timezone): array  // {year,available_years,timezone,check_types,series}
protected function buildTodayChecks(Monitor $monitor, string $checkType, string $timezone): array  // today only, ->limit(200), newest first, mapped rows
protected function buildRecentChecks(Monitor $monitor, string $type, Carbon $fromUtc, Carbon $toUtc, string $timezone): array  // {type,data,pagination} via ->paginate(25)
// existing reused: resolveHistoryRange(), buildSummary(), parseDateInput()
```
Request params read by `show()`: `year` (graph), `preset|from|to` (filters/summary/recentChecks range), `recent_type` (default `'uptime'`), `recent_page` (default 1).

### JS utility signatures (exact exports)

```js
// resources/js/Utils/formatDate.js
export function formatDateUTC(iso)        // 'YYYY-MM-DD'|ISO -> '27 Mar 2026'
export function formatDateTimeUTC(iso)    // -> '27 Mar 2026, 15:00'
export function formatRelative(iso, nowMs)// -> 'just now' | '5m ago' | '3h ago' | '2d ago'

// resources/js/Utils/heatmapCalendar.js
export function buildYearGrid(year)       // -> { weeks: Array<Array<{ iso:'YYYY-MM-DD', inYear:boolean }>> } ; Sun..Sat rows, full Jan1..Dec31 padded to whole weeks (pad days inYear:false)
export function monthLabelColumns(weeks)  // -> Array<{ label:'Jan'..'Dec', colIndex:number }> aligned to first week-column containing each month's day 1; drops a label if <3 cols from previous
export function computeCellSize(containerWidth, weekCount, opts) // opts={gap,min,max} -> integer px cell size that fits width, clamped [min,max]

// resources/js/Utils/checkStatusSeverity.js  (ADD to existing file)
export const CHECK_TYPE_STATUSES = { uptime:['success','failed','unknown'], domain:['success','warning','failed','unknown'], certificate:['success','failed','unknown'] }
export function statusesForCheckType(checkType) // -> string[] (all four if unknown type)
```
Existing `checkStatusSeverity.js` exports remain: `CHECK_STATUS`, `normalizeCheckStatus`, `getCheckStatusMeta`, `getCheckStatusBadgeColor`, `mapUptimeStatusToCheckStatus`. `CHECK_STATUS_META[status].heatmapClass` is the solid per-status color used by the today-bar.

### Component contracts (props)

```
Tooltip({ content, children, className })             // hover+focus; role=tooltip; aria-describedby wired; content = string|node
MonitorHistoryHeatmap({ checkType, title, description, year, points, todayIso })
                                                       // points = series[type].daily_metrics; full-year grid; month axis; today ring; per-metric legend; focusable cells + Tooltip
MonitorTodayBar({ checkType, checks })                // checks = series[type].today_checks; thin segments newest->oldest, most-recent-that-fit; per-segment Tooltip
MonitorHistoryFilters({ filters, pending, onApply })  // onApply({preset}|{preset:'custom',from,to}); inline row; matched h-9; aria-pressed presets; focus rings
SummaryStats({ summary })                             // reliability % lead card + supporting success/warning/failed/unknown + all-time compare + empty-state branch
RecentChecksPanel({ recentChecks, checkTypes, pending, onTabChange, onPageChange })
                                                       // tabs uptime/domain; table Time/Type/Status/Message/Response(ms); numbered pager
MonitorLiveStatus({ monitor })                         // UP/DOWN/PENDING pill + 'last checked X ago' + failure reason when down
```

### Show.jsx param helper (Task 3.x, consumed everywhere)
```js
// current = { year, preset, from, to, recent_type, recent_page } derived from props
export function buildHistoryParams(current, overrides) // -> merged plain object for router.get params
```

---

## Phases (each ends with a working, reviewable deliverable)

- **Phase 1 — Foundations & pure utils (Vitest TDD):** Vitest setup; `checkStatusSeverity` per-type status map; `formatDate`; `heatmapCalendar`; `Tooltip` component. No page wiring yet.
- **Phase 2 — Backend payload restructure (PHPUnit TDD):** `show()` returns `graph`/`filters`/`summary`/`recentChecks`; `year`+`available_years`; `today_checks`; recent checks `paginate(25)` + `recent_type`; `first_checked_at`; cert omitted from `check_types`.
- **Phase 3 — Graphs section (build-verified):** rebuild `MonitorHistoryHeatmap` (full-year, month axis, today highlight, a11y, styled tooltips, per-metric legend); `MonitorTodayBar`; year nav; per-type headline; wire into `Show.jsx` graphs section (position 1, decoupled).
- **Phase 4 — Filters + Summary (build-verified):** `MonitorHistoryFilters` inline row (position 2, filter-driven only); `SummaryStats` reliability-led KPIs with unknown reconciliation, all-time compare, empty-state disambiguation (position 3); timezone label.
- **Phase 5 — Recent Checks panel (build-verified; backend in P2):** `RecentChecksPanel` tabs + numbered pagination + Response column + row hover + badge icons (position 4).
- **Phase 6 — Header, live status & polish:** Back inline-left; `MonitorLiveStatus` hero; 2-tier headers; flatten nested cards; compact snapshot strip; de-emphasize disabled checks; reduced-motion + final a11y/contrast pass.

## Known deferrals (not tasked here)

- **E3 range-level avg/p95 latency figure** near the uptime heatmap. The Response (ms) column (Phase 5) and the per-day tooltip avg/p95 (Phase 3) cover latency; a *range-level* figure would need a dedicated backend rollup (averaging daily averages / p95s is statistically unsound), so it is deferred rather than approximated.
- **Tier-2 enhancements** (per the approved §G of the spec): certificate enable-path wiring + SSL/domain expiry badges, outage/incident timeline (last/longest outage, MTTR), uptime/downtime streaks, custom-range validation feedback, CSV export. Tracked in `plans/monitor-history-ui-enhancements-spec.md` §E Tier 2.

---

## Phase 1 — Foundations & pure utils (Vitest TDD)

**Deliverable:** Vitest wired into the project (`npm run test:js`), `checkStatusSeverity.js` extended with a per-check-type status map, two new fully unit-tested pure utilities (`formatDate.js`, `heatmapCalendar.js`), and a reusable build-verified `Tooltip.jsx` — all with no page wiring yet.

### Task 1.1: Add Vitest tooling, config, and smoke test

**Files:**
- Modify: `/Users/vaibhav/projects/coloredcow/monitor/package.json`
- Create: `/Users/vaibhav/projects/coloredcow/monitor/vitest.config.js`
- Create (test): `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/__tests__/smoke.test.js`

**Interfaces:**
- Produces: `npm run test:js` script (`"test:js": "vitest run"`) consumed by every later frontend-util task in the spine.
- Consumes: existing `@vitejs/plugin-react` devDependency (already present in `package.json`); the `@` alias already used across `resources/js` source (e.g. `@/Utils/checkStatusSeverity`).

- [ ] **Step 1: Install Vitest as a devDependency.**
```bash
npm install --save-dev vitest@^2.1.9
```

- [ ] **Step 2: Add the `test:js` script to `package.json`.** Replace the `scripts` block:
```json
    "scripts": {
        "dev": "vite",
        "build": "vite build",
        "test:js": "vitest run"
    },
```

- [ ] **Step 3: Create `vitest.config.js`** (pure JS utils — no jsdom; resolve the `@` alias so utils can import siblings):
```js
import { defineConfig } from "vitest/config";
import { fileURLToPath } from "node:url";

export default defineConfig({
    resolve: {
        alias: {
            "@": fileURLToPath(new URL("./resources/js", import.meta.url)),
        },
    },
    test: {
        environment: "node",
        include: ["resources/js/**/*.{test,spec}.{js,jsx}"],
        globals: false,
    },
});
```

- [ ] **Step 4: Create the smoke test** at `resources/js/Utils/__tests__/smoke.test.js`:
```js
import { describe, it, expect } from "vitest";

describe("vitest smoke", () => {
    it("runs the test runner", () => {
        expect(1 + 1).toBe(2);
    });
});
```

- [ ] **Step 5: Verify** — run:
```bash
npm run test:js -- resources/js/Utils/__tests__/smoke.test.js
```
Expected: PASS — `1 passed` (1 test), exit code 0.

- [ ] **Step 6: Commit.**
```bash
git add package.json package-lock.json vitest.config.js resources/js/Utils/__tests__/smoke.test.js && git commit -m "Add Vitest tooling, config, and smoke test for pure JS utils"
```

### Task 1.2: Add per-check-type status map to `checkStatusSeverity.js` (TDD)

**Files:**
- Create (test): `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/__tests__/checkStatusSeverity.test.js`
- Modify: `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/checkStatusSeverity.js`

**Interfaces:**
- Produces (exact, per spine):
  - `export const CHECK_TYPE_STATUSES = { uptime:['success','failed','unknown'], domain:['success','warning','failed','unknown'], certificate:['success','failed','unknown'] }`
  - `export function statusesForCheckType(checkType)` → `string[]` (all four statuses for an unknown type).
- Consumes: existing `CHECK_STATUS` export already in this file (`success`/`warning`/`failed`/`unknown` string values). Existing exports (`CHECK_STATUS`, `normalizeCheckStatus`, `getCheckStatusMeta`, `getCheckStatusBadgeColor`, `mapUptimeStatusToCheckStatus`) MUST remain unchanged.

- [ ] **Step 1: Write the failing test** at `resources/js/Utils/__tests__/checkStatusSeverity.test.js`:
```js
import { describe, it, expect } from "vitest";
import {
    CHECK_TYPE_STATUSES,
    statusesForCheckType,
} from "@/Utils/checkStatusSeverity";

describe("CHECK_TYPE_STATUSES", () => {
    it("uptime omits warning", () => {
        expect(CHECK_TYPE_STATUSES.uptime).toEqual([
            "success",
            "failed",
            "unknown",
        ]);
    });

    it("certificate omits warning", () => {
        expect(CHECK_TYPE_STATUSES.certificate).toEqual([
            "success",
            "failed",
            "unknown",
        ]);
    });

    it("domain includes warning", () => {
        expect(CHECK_TYPE_STATUSES.domain).toEqual([
            "success",
            "warning",
            "failed",
            "unknown",
        ]);
    });
});

describe("statusesForCheckType", () => {
    it("returns the uptime list for 'uptime'", () => {
        expect(statusesForCheckType("uptime")).toEqual([
            "success",
            "failed",
            "unknown",
        ]);
    });

    it("returns the domain list for 'domain'", () => {
        expect(statusesForCheckType("domain")).toEqual([
            "success",
            "warning",
            "failed",
            "unknown",
        ]);
    });

    it("returns the certificate list for 'certificate'", () => {
        expect(statusesForCheckType("certificate")).toEqual([
            "success",
            "failed",
            "unknown",
        ]);
    });

    it("falls back to all four statuses for an unknown type", () => {
        expect(statusesForCheckType("bogus")).toEqual([
            "success",
            "warning",
            "failed",
            "unknown",
        ]);
    });

    it("falls back for undefined input", () => {
        expect(statusesForCheckType(undefined)).toEqual([
            "success",
            "warning",
            "failed",
            "unknown",
        ]);
    });

    it("returns a copy, not the shared array reference", () => {
        const result = statusesForCheckType("uptime");
        result.push("mutated");
        expect(CHECK_TYPE_STATUSES.uptime).toEqual([
            "success",
            "failed",
            "unknown",
        ]);
    });
});
```

- [ ] **Step 2: Run the test, expect FAIL.**
```bash
npm run test:js -- resources/js/Utils/__tests__/checkStatusSeverity.test.js
```
Expected: FAIL — import resolves but `CHECK_TYPE_STATUSES`/`statusesForCheckType` are `undefined` (e.g. "Cannot read properties of undefined" / `statusesForCheckType is not a function`).

- [ ] **Step 3: Append the new exports** to `resources/js/Utils/checkStatusSeverity.js` (after the existing `mapUptimeStatusToCheckStatus` function, at end of file):
```js
export const CHECK_TYPE_STATUSES = Object.freeze({
    uptime: [CHECK_STATUS.SUCCESS, CHECK_STATUS.FAILED, CHECK_STATUS.UNKNOWN],
    domain: [
        CHECK_STATUS.SUCCESS,
        CHECK_STATUS.WARNING,
        CHECK_STATUS.FAILED,
        CHECK_STATUS.UNKNOWN,
    ],
    certificate: [
        CHECK_STATUS.SUCCESS,
        CHECK_STATUS.FAILED,
        CHECK_STATUS.UNKNOWN,
    ],
});

const ALL_CHECK_STATUSES = [
    CHECK_STATUS.SUCCESS,
    CHECK_STATUS.WARNING,
    CHECK_STATUS.FAILED,
    CHECK_STATUS.UNKNOWN,
];

export function statusesForCheckType(checkType) {
    const statuses = CHECK_TYPE_STATUSES[checkType] || ALL_CHECK_STATUSES;

    return [...statuses];
}
```

- [ ] **Step 4: Run the test, expect PASS.**
```bash
npm run test:js -- resources/js/Utils/__tests__/checkStatusSeverity.test.js
```
Expected: PASS — `9 passed`, exit code 0.

- [ ] **Step 5: Commit.**
```bash
git add resources/js/Utils/checkStatusSeverity.js resources/js/Utils/__tests__/checkStatusSeverity.test.js && git commit -m "Add CHECK_TYPE_STATUSES and statusesForCheckType per check type"
```

### Task 1.3: Create `formatDate.js` UTC formatters (TDD)

**Files:**
- Create (test): `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/__tests__/formatDate.test.js`
- Create: `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/formatDate.js`

**Interfaces:**
- Produces (exact, per spine):
  - `export function formatDateUTC(iso)` → `'27 Mar 2026'`
  - `export function formatDateTimeUTC(iso)` → `'27 Mar 2026, 15:00'`
  - `export function formatRelative(iso, nowMs)` → `'just now' | '5m ago' | '3h ago' | '2d ago'`
- Consumes: ISO strings already shifted to the server timezone (Global Constraint: JS formatters MUST use `timeZone: 'UTC'`). Accepts both `'YYYY-MM-DD'` and `'YYYY-MM-DD HH:mm:ss'` (the `checked_at` payload shape). `nowMs` is a millisecond epoch (e.g. `Date.now()`).

- [ ] **Step 1: Write the failing test** at `resources/js/Utils/__tests__/formatDate.test.js`:
```js
import { describe, it, expect } from "vitest";
import {
    formatDateUTC,
    formatDateTimeUTC,
    formatRelative,
} from "@/Utils/formatDate";

describe("formatDateUTC", () => {
    it("formats a date-only string as '27 Mar 2026'", () => {
        expect(formatDateUTC("2026-03-27")).toBe("27 Mar 2026");
    });

    it("formats a datetime string using its date part", () => {
        expect(formatDateUTC("2026-03-27 15:00:00")).toBe("27 Mar 2026");
    });

    it("formats a leap day correctly", () => {
        expect(formatDateUTC("2024-02-29")).toBe("29 Feb 2024");
    });

    it("does not shift across days at UTC midnight", () => {
        expect(formatDateUTC("2026-01-01 00:00:00")).toBe("01 Jan 2026");
    });

    it("returns empty string for null/empty input", () => {
        expect(formatDateUTC(null)).toBe("");
        expect(formatDateUTC("")).toBe("");
    });
});

describe("formatDateTimeUTC", () => {
    it("formats a datetime as '27 Mar 2026, 15:00'", () => {
        expect(formatDateTimeUTC("2026-03-27 15:00:00")).toBe(
            "27 Mar 2026, 15:00"
        );
    });

    it("pads single-digit hours and minutes", () => {
        expect(formatDateTimeUTC("2026-03-27 09:05:00")).toBe(
            "27 Mar 2026, 09:05"
        );
    });

    it("renders midnight as 00:00 (24h, no shift)", () => {
        expect(formatDateTimeUTC("2026-01-01 00:00:00")).toBe(
            "01 Jan 2026, 00:00"
        );
    });

    it("returns empty string for null input", () => {
        expect(formatDateTimeUTC(null)).toBe("");
    });
});

describe("formatRelative", () => {
    const base = Date.UTC(2026, 2, 27, 15, 0, 0); // 2026-03-27 15:00:00 UTC

    it("returns 'just now' for under a minute", () => {
        expect(formatRelative("2026-03-27 14:59:30", base)).toBe("just now");
    });

    it("returns minutes for under an hour", () => {
        expect(formatRelative("2026-03-27 14:55:00", base)).toBe("5m ago");
    });

    it("returns hours for under a day", () => {
        expect(formatRelative("2026-03-27 12:00:00", base)).toBe("3h ago");
    });

    it("returns days for a day or more", () => {
        expect(formatRelative("2026-03-25 15:00:00", base)).toBe("2d ago");
    });

    it("clamps future timestamps to 'just now'", () => {
        expect(formatRelative("2026-03-27 15:00:30", base)).toBe("just now");
    });

    it("returns empty string for null input", () => {
        expect(formatRelative(null, base)).toBe("");
    });
});
```

- [ ] **Step 2: Run the test, expect FAIL.**
```bash
npm run test:js -- resources/js/Utils/__tests__/formatDate.test.js
```
Expected: FAIL — module `@/Utils/formatDate` cannot be resolved ("Failed to resolve import" / "Cannot find module").

- [ ] **Step 3: Create `resources/js/Utils/formatDate.js`:**
```js
// All formatting is done in UTC by design. The backend ships ISO strings that
// have already been shifted into the configured server timezone, so we must NOT
// re-shift them into the browser timezone (Global Constraint).

function toUTCDate(iso) {
    if (!iso) {
        return null;
    }

    // Accept 'YYYY-MM-DD' and 'YYYY-MM-DD HH:mm:ss' (the checked_at payload shape).
    const normalized = String(iso).trim().replace(" ", "T");
    const [datePart, timePart = "00:00:00"] = normalized.split("T");
    const [year, month, day] = datePart.split("-").map(Number);
    const [hour = 0, minute = 0, second = 0] = timePart
        .split(":")
        .map(Number);

    if (!year || !month || !day) {
        return null;
    }

    return new Date(Date.UTC(year, month - 1, day, hour, minute, second));
}

const DATE_FORMATTER = new Intl.DateTimeFormat("en-GB", {
    timeZone: "UTC",
    day: "2-digit",
    month: "short",
    year: "numeric",
});

const TIME_FORMATTER = new Intl.DateTimeFormat("en-GB", {
    timeZone: "UTC",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
});

export function formatDateUTC(iso) {
    const date = toUTCDate(iso);

    if (!date) {
        return "";
    }

    // en-GB '2-digit/short/numeric' yields '27 Mar 2026'.
    return DATE_FORMATTER.format(date);
}

export function formatDateTimeUTC(iso) {
    const date = toUTCDate(iso);

    if (!date) {
        return "";
    }

    return `${DATE_FORMATTER.format(date)}, ${TIME_FORMATTER.format(date)}`;
}

export function formatRelative(iso, nowMs) {
    const date = toUTCDate(iso);

    if (!date) {
        return "";
    }

    const deltaMs = Number(nowMs) - date.getTime();
    const deltaSeconds = Math.floor(deltaMs / 1000);

    if (deltaSeconds < 60) {
        return "just now";
    }

    const deltaMinutes = Math.floor(deltaSeconds / 60);
    if (deltaMinutes < 60) {
        return `${deltaMinutes}m ago`;
    }

    const deltaHours = Math.floor(deltaMinutes / 60);
    if (deltaHours < 24) {
        return `${deltaHours}h ago`;
    }

    const deltaDays = Math.floor(deltaHours / 24);
    return `${deltaDays}d ago`;
}
```

- [ ] **Step 4: Run the test, expect PASS.**
```bash
npm run test:js -- resources/js/Utils/__tests__/formatDate.test.js
```
Expected: PASS — `15 passed`, exit code 0.

- [ ] **Step 5: Commit.**
```bash
git add resources/js/Utils/formatDate.js resources/js/Utils/__tests__/formatDate.test.js && git commit -m "Add UTC date/time/relative formatters in formatDate util"
```

### Task 1.4: Create `heatmapCalendar.js` grid/label/sizing utils (TDD)

**Files:**
- Create (test): `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/__tests__/heatmapCalendar.test.js`
- Create: `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/heatmapCalendar.js`

**Interfaces:**
- Produces (exact, per spine):
  - `export function buildYearGrid(year)` → `{ weeks: Array<Array<{ iso:'YYYY-MM-DD', inYear:boolean }>> }`; Sun..Sat rows, full Jan1..Dec31 padded to whole weeks (pad days `inYear:false`).
  - `export function monthLabelColumns(weeks)` → `Array<{ label:'Jan'..'Dec', colIndex:number }>` aligned to the first week-column containing each month's day 1; drops a label if `<3` cols from the previous kept label.
  - `export function computeCellSize(containerWidth, weekCount, opts)` with `opts={gap,min,max}` → integer px cell size that fits width, clamped `[min,max]`.
- Consumes: nothing external (pure date math via `Date.UTC`).

- [ ] **Step 1: Write the failing test** at `resources/js/Utils/__tests__/heatmapCalendar.test.js`:
```js
import { describe, it, expect } from "vitest";
import {
    buildYearGrid,
    monthLabelColumns,
    computeCellSize,
} from "@/Utils/heatmapCalendar";

describe("buildYearGrid", () => {
    it("starts each week on Sunday and ends on Saturday", () => {
        const { weeks } = buildYearGrid(2026);
        for (const week of weeks) {
            expect(week).toHaveLength(7);
        }
        // 2026-01-01 is a Thursday, so the first week's Sunday is 2025-12-28.
        expect(weeks[0][0].iso).toBe("2025-12-28");
        expect(weeks[0][0].inYear).toBe(false);
    });

    it("includes Jan 1 and Dec 31 of the target year as inYear cells", () => {
        const { weeks } = buildYearGrid(2026);
        const flat = weeks.flat();
        const jan1 = flat.find((c) => c.iso === "2026-01-01");
        const dec31 = flat.find((c) => c.iso === "2026-12-31");
        expect(jan1.inYear).toBe(true);
        expect(dec31.inYear).toBe(true);
    });

    it("marks padding days as inYear:false", () => {
        const { weeks } = buildYearGrid(2026);
        const flat = weeks.flat();
        const padBefore = flat.find((c) => c.iso === "2025-12-31");
        expect(padBefore.inYear).toBe(false);
    });

    it("counts exactly 365 in-year days for a common year", () => {
        const { weeks } = buildYearGrid(2026);
        const inYearCount = weeks.flat().filter((c) => c.inYear).length;
        expect(inYearCount).toBe(365);
    });

    it("counts exactly 366 in-year days for the 2024 leap year", () => {
        const { weeks } = buildYearGrid(2024);
        const flat = weeks.flat();
        const inYearCount = flat.filter((c) => c.inYear).length;
        expect(inYearCount).toBe(366);
        expect(flat.find((c) => c.iso === "2024-02-29").inYear).toBe(true);
    });

    it("pads to whole weeks (total cells divisible by 7)", () => {
        const { weeks } = buildYearGrid(2024);
        expect(weeks.flat().length % 7).toBe(0);
    });
});

describe("monthLabelColumns", () => {
    it("returns 12 month labels for a full year by default spacing", () => {
        const { weeks } = buildYearGrid(2026);
        const labels = monthLabelColumns(weeks);
        expect(labels[0].label).toBe("Jan");
        expect(labels.every((l) => /^[A-Z][a-z]{2}$/.test(l.label))).toBe(
            true
        );
        // colIndex must be a valid, ascending week column.
        for (let i = 1; i < labels.length; i += 1) {
            expect(labels[i].colIndex).toBeGreaterThan(labels[i - 1].colIndex);
            expect(labels[i].colIndex).toBeLessThan(weeks.length);
        }
    });

    it("drops a label that is fewer than 3 columns from the previous kept one", () => {
        // Two months whose day-1 columns are only 2 apart -> second is dropped.
        const weeks = [
            [{ iso: "2026-01-01", inYear: true }],
            [{ iso: "2026-01-08", inYear: true }],
            [{ iso: "2026-02-01", inYear: true }],
        ];
        const labels = monthLabelColumns(weeks);
        expect(labels).toHaveLength(1);
        expect(labels[0]).toEqual({ label: "Jan", colIndex: 0 });
    });

    it("keeps a label that is at least 3 columns from the previous kept one", () => {
        const weeks = [
            [{ iso: "2026-01-01", inYear: true }],
            [{ iso: "2026-01-08", inYear: true }],
            [{ iso: "2026-01-15", inYear: true }],
            [{ iso: "2026-02-01", inYear: true }],
        ];
        const labels = monthLabelColumns(weeks);
        expect(labels).toEqual([
            { label: "Jan", colIndex: 0 },
            { label: "Feb", colIndex: 3 },
        ]);
    });
});

describe("computeCellSize", () => {
    const opts = { gap: 4, min: 8, max: 16 };

    it("fits the cells plus gaps inside the container width", () => {
        // 53 weeks, container 900px: (size+gap)*weeks - gap <= width.
        const size = computeCellSize(900, 53, opts);
        expect(size).toBeGreaterThanOrEqual(opts.min);
        expect(size).toBeLessThanOrEqual(opts.max);
        expect((size + opts.gap) * 53 - opts.gap).toBeLessThanOrEqual(900);
    });

    it("clamps to max when the container is very wide", () => {
        expect(computeCellSize(5000, 53, opts)).toBe(16);
    });

    it("clamps to min when the container is very narrow", () => {
        expect(computeCellSize(100, 53, opts)).toBe(8);
    });

    it("returns an integer", () => {
        expect(Number.isInteger(computeCellSize(763, 53, opts))).toBe(true);
    });

    it("falls back to min for non-positive width", () => {
        expect(computeCellSize(0, 53, opts)).toBe(8);
    });
});
```

- [ ] **Step 2: Run the test, expect FAIL.**
```bash
npm run test:js -- resources/js/Utils/__tests__/heatmapCalendar.test.js
```
Expected: FAIL — module `@/Utils/heatmapCalendar` cannot be resolved ("Failed to resolve import").

- [ ] **Step 3: Create `resources/js/Utils/heatmapCalendar.js`:**
```js
const MONTH_LABELS = [
    "Jan",
    "Feb",
    "Mar",
    "Apr",
    "May",
    "Jun",
    "Jul",
    "Aug",
    "Sep",
    "Oct",
    "Nov",
    "Dec",
];

const MS_PER_DAY = 24 * 60 * 60 * 1000;

function isoFromUTCDate(date) {
    const year = date.getUTCFullYear();
    const month = String(date.getUTCMonth() + 1).padStart(2, "0");
    const day = String(date.getUTCDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
}

export function buildYearGrid(year) {
    const yearStart = new Date(Date.UTC(year, 0, 1));
    const yearEnd = new Date(Date.UTC(year, 11, 31));

    // Pad back to the Sunday on/before Jan 1, forward to the Saturday on/after Dec 31.
    const gridStart = new Date(
        yearStart.getTime() - yearStart.getUTCDay() * MS_PER_DAY
    );
    const gridEnd = new Date(
        yearEnd.getTime() + (6 - yearEnd.getUTCDay()) * MS_PER_DAY
    );

    const weeks = [];
    let currentWeek = [];

    for (
        let cursor = gridStart.getTime();
        cursor <= gridEnd.getTime();
        cursor += MS_PER_DAY
    ) {
        const date = new Date(cursor);
        currentWeek.push({
            iso: isoFromUTCDate(date),
            inYear: date.getUTCFullYear() === year,
        });

        if (currentWeek.length === 7) {
            weeks.push(currentWeek);
            currentWeek = [];
        }
    }

    return { weeks };
}

export function monthLabelColumns(weeks) {
    const labels = [];
    let lastKeptColIndex = -Infinity;
    const seenMonths = new Set();

    weeks.forEach((week, colIndex) => {
        for (const cell of week) {
            if (!cell.inYear) {
                continue;
            }

            const [, monthStr, dayStr] = cell.iso.split("-");
            if (dayStr !== "01") {
                continue;
            }

            const monthIndex = Number(monthStr) - 1;
            if (seenMonths.has(monthIndex)) {
                continue;
            }
            seenMonths.add(monthIndex);

            if (colIndex - lastKeptColIndex < 3 && labels.length > 0) {
                continue;
            }

            labels.push({ label: MONTH_LABELS[monthIndex], colIndex });
            lastKeptColIndex = colIndex;
        }
    });

    return labels;
}

export function computeCellSize(containerWidth, weekCount, opts) {
    const { gap, min, max } = opts;

    if (!containerWidth || containerWidth <= 0 || weekCount <= 0) {
        return min;
    }

    // Total width = (size + gap) * weekCount - gap; solve for size.
    const raw = Math.floor((containerWidth + gap) / weekCount) - gap;

    return Math.max(min, Math.min(max, raw));
}
```

- [ ] **Step 4: Run the test, expect PASS.**
```bash
npm run test:js -- resources/js/Utils/__tests__/heatmapCalendar.test.js
```
Expected: PASS — `13 passed`, exit code 0.

- [ ] **Step 5: Commit.**
```bash
git add resources/js/Utils/heatmapCalendar.js resources/js/Utils/__tests__/heatmapCalendar.test.js && git commit -m "Add heatmapCalendar grid, month-label, and cell-size utils"
```

### Task 1.5: Create reusable `Tooltip.jsx` (build-verified)

**Files:**
- Create: `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Components/Tooltip.jsx`

**Interfaces:**
- Produces (exact, per spine component contract): `Tooltip({ content, children, className })` — hover+focus; `role="tooltip"`; `aria-describedby` wired; positioned; `content = string|node`.
- Consumes: React 19 (already a dependency). No DOM test runner exists for `.jsx` — verification is `npm run build` plus the Phase 3 backend feature tests that assert the heatmap/today-bar payload this component will later render against. Must honor Global Constraints: `motion-reduce:transition-none`, `focus-visible` reachable wrapper, `tabular-nums` left to callers.

- [ ] **Step 1: Create `resources/js/Components/Tooltip.jsx`:**
```jsx
import React, { useId, useState } from "react";

// Reusable hover + keyboard-focus tooltip.
// - role="tooltip" with aria-describedby wired to the trigger for screen readers.
// - Shows on mouse hover AND keyboard focus (focus-visible reachable trigger).
// - Positioned above the trigger, centered; pointer-events disabled so it never
//   steals hover. Motion is gated with motion-reduce:* per Global Constraints.
export default function Tooltip({ content, children, className = "" }) {
    const tooltipId = useId();
    const [open, setOpen] = useState(false);

    const show = () => setOpen(true);
    const hide = () => setOpen(false);

    if (content === null || content === undefined || content === "") {
        return children;
    }

    return (
        <span
            className={`relative inline-flex ${className}`}
            onMouseEnter={show}
            onMouseLeave={hide}
            onFocus={show}
            onBlur={hide}
        >
            <span aria-describedby={open ? tooltipId : undefined}>
                {children}
            </span>

            <span
                role="tooltip"
                id={tooltipId}
                className={[
                    "pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 -translate-x-1/2",
                    "whitespace-pre-line rounded-md bg-gray-900 px-2.5 py-1.5",
                    "text-xs font-medium leading-snug text-white shadow-lg",
                    "transition-opacity duration-150 ease-out",
                    "motion-reduce:transition-none motion-reduce:transform-none",
                    open ? "opacity-100" : "opacity-0",
                ].join(" ")}
                aria-hidden={open ? undefined : "true"}
            >
                {content}
            </span>
        </span>
    );
}
```

- [ ] **Step 2: Verify the build compiles.**
```bash
npm run build
```
Expected: build succeeds ("built in ... ms", exit code 0), no Vite/React errors referencing `Tooltip.jsx`. Note: this component has no runtime wiring yet; Phase 3's backend feature tests (`MonitorHistoryShowTest` assertions on `graph.series.*.daily_metrics` / `today_checks`) cover the payload it will render once consumed by `MonitorHistoryHeatmap` and `MonitorTodayBar`.

- [ ] **Step 3: Commit.**
```bash
git add resources/js/Components/Tooltip.jsx && git commit -m "Add reusable accessible Tooltip component (hover + focus)"
```

---

Phase 1 authored and appended-ready. Key file paths produced by this phase:
- `/Users/vaibhav/projects/coloredcow/monitor/vitest.config.js`
- `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/formatDate.js`
- `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/heatmapCalendar.js`
- `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/checkStatusSeverity.js` (extended)
- `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Components/Tooltip.jsx`
- Tests under `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/__tests__/`

Notes for the orchestrator: (1) Vitest is pinned to `^2.1.9` (Vite 7-compatible, Node 24 confirmed present). (2) The `@` alias is re-declared in `vitest.config.js` because Vitest does not inherit Laravel's Vite alias resolution. (3) `formatDateUTC` relies on `Intl.DateTimeFormat('en-GB', …)` to emit the locked `27 Mar 2026` format; if a future Node/ICU build alters en-GB spacing the `formatDate.test.js` assertions will catch it.


---

## Phase 2 — Backend payload restructure (PHPUnit TDD)

**Deliverable:** `MonitorsController@show` returns top-level `graph`/`filters`/`summary`/`recentChecks` props (each `null` when the feature flag is off) per the spine schema, with `availableYears()`, `resolveGraphYear()`, `graphCheckTypes()`, `buildGraphPayload()`, `buildTodayChecks()`, `buildRecentChecks()` helpers implemented and `summary.first_checked_at` added; all behaviors covered by PHPUnit feature tests on `monitors.show`.

### Task 2.1: Add per-type graph payload helpers (`graphCheckTypes`, `availableYears`, `resolveGraphYear`)

**Files:**
- modify: `app/Http/Controllers/MonitorsController.php`
- test: `tests/Feature/MonitorHistory/MonitorHistoryGraphTest.php` (create)

**Interfaces:**
- Produces `protected function graphCheckTypes(Monitor $monitor): array` → `[{type:'uptime',enabled:bool},{type:'domain',enabled:bool}]` (certificate omitted)
- Produces `protected function availableYears(Monitor $monitor, string $timezone): array` → ascending `[minYear..currentYear]`, or `[currentYear]` when no data
- Produces `protected function resolveGraphYear(Request $request, array $availableYears): int` → reads `?year`, defaults to current year (in `$timezone` via caller), clamped into `$availableYears`
- Consumes existing `MonitorCheckLogService::CHECK_TYPE_UPTIME`, `CHECK_TYPE_DOMAIN`; `$monitor->checkLogs()`

- [ ] **Step 1: Create the failing test file with graph-type and available-years cases.**
```php
<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorHistoryGraphTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['monitor-history.enabled' => true]);
    }

    private function makeMonitor(array $attributes = []): Monitor
    {
        return Monitor::create(array_merge([
            'url' => 'https://example-'.uniqid().'.com',
            'uptime_check_enabled' => true,
            'domain_check_enabled' => false,
            'certificate_check_enabled' => false,
        ], $attributes));
    }

    private function seedUptimeLog(Monitor $monitor, string $status, string $checkedAt): void
    {
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: $status,
            checkedAt: Carbon::parse($checkedAt),
        );
    }

    public function test_graph_check_types_exclude_certificate(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor([
            'uptime_check_enabled' => true,
            'domain_check_enabled' => true,
            'certificate_check_enabled' => true,
        ]);

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.check_types', fn ($types) => collect($types)->pluck('type')->all() === ['uptime', 'domain']
                && collect($types)->firstWhere('type', 'uptime')['enabled'] === true
                && collect($types)->firstWhere('type', 'domain')['enabled'] === true
            )
        );
    }

    public function test_graph_year_defaults_to_current_year_when_no_param(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.year', (int) Carbon::now('UTC')->format('Y'))
        );
    }

    public function test_graph_year_param_overrides_default(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2024-05-10 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'year' => 2024,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.year', 2024)
        );
    }

    public function test_available_years_span_earliest_data_year_through_current_year(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2024-01-15 10:00:00');

        $currentYear = (int) Carbon::now('UTC')->format('Y');
        $expected = range(2024, $currentYear);

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.available_years', $expected)
        );
    }

    public function test_available_years_falls_back_to_current_year_when_no_data(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.available_years', [(int) Carbon::now('UTC')->format('Y')])
        );
    }
}
```

- [ ] **Step 2: Run the new test and confirm it fails.**
```
php artisan config:clear && mysql -u root -e "CREATE DATABASE IF NOT EXISTS monitor_test;" && php artisan test --filter MonitorHistoryGraphTest
```
Expected: FAIL (props `graph.check_types` / `graph.year` / `graph.available_years` are missing — `graph` does not exist yet).

- [ ] **Step 3: Add the `graphCheckTypes()` helper after `buildSummary()` in `MonitorsController`.**
```php
    protected function graphCheckTypes(Monitor $monitor): array
    {
        return [
            [
                'type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
                'enabled' => (bool) $monitor->uptime_check_enabled,
            ],
            [
                'type' => MonitorCheckLogService::CHECK_TYPE_DOMAIN,
                'enabled' => (bool) $monitor->domain_check_enabled,
            ],
        ];
    }
```

- [ ] **Step 4: Add the `availableYears()` helper below `graphCheckTypes()`.**
```php
    protected function availableYears(Monitor $monitor, string $timezone): array
    {
        $currentYear = (int) Carbon::now($timezone)->format('Y');

        $firstCheckedAt = $monitor->checkLogs()->orderBy('checked_at')->value('checked_at');

        if (! $firstCheckedAt) {
            return [$currentYear];
        }

        $minYear = (int) Carbon::parse($firstCheckedAt)->timezone($timezone)->format('Y');

        if ($minYear > $currentYear) {
            $minYear = $currentYear;
        }

        return range($minYear, $currentYear);
    }
```

- [ ] **Step 5: Add the `resolveGraphYear()` helper below `availableYears()`.**
```php
    protected function resolveGraphYear(Request $request, array $availableYears): int
    {
        $default = end($availableYears) ?: (int) Carbon::now('UTC')->format('Y');

        $requested = $request->integer('year') ?: $default;

        if (! in_array((int) $requested, $availableYears, true)) {
            return (int) $default;
        }

        return (int) $requested;
    }
```

- [ ] **Step 6: Temporarily expose a minimal `graph` prop in `show()` so Step 1 tests pass — replace the `'history' => $history,` Inertia render array (this is wired fully in Task 2.4, this step only adds the partial graph slice).** Inside the `if (config('monitor-history.enabled'))` block, after `$timezone = $range['timezone'];`, add:
```php
            $availableYears = $this->availableYears($monitor, $timezone);
            $graphYear = $this->resolveGraphYear($request, $availableYears);
            $graph = [
                'year' => $graphYear,
                'available_years' => $availableYears,
                'timezone' => $timezone,
                'check_types' => $this->graphCheckTypes($monitor),
                'series' => [],
            ];
```
Then add `$graph = null;` next to `$history = null;` at the top of `show()`, and add `'graph' => $graph,` to the `Inertia::render('Monitors/Show', [...])` array.

- [ ] **Step 7: Run the test and confirm it passes.**
```
php artisan test --filter MonitorHistoryGraphTest
```
Expected: PASS (all 5 tests green).

- [ ] **Step 8: Run Pint on changed files.**
```
vendor/bin/pint --dirty
```
Expected: no style errors reported.

- [ ] **Step 9: Commit.**
```
git add app/Http/Controllers/MonitorsController.php tests/Feature/MonitorHistory/MonitorHistoryGraphTest.php && git commit -m "Add graph check-type, available-years and year-resolution helpers"
```

### Task 2.2: Build per-type graph series — `daily_metrics` (full calendar year) + per-type `summary`

**Files:**
- modify: `app/Http/Controllers/MonitorsController.php`
- test: `tests/Feature/MonitorHistory/MonitorHistoryGraphTest.php`

**Interfaces:**
- Produces `protected function buildGraphPayload(Monitor $monitor, int $year, string $timezone): array` → `{year, available_years, timezone, check_types, series}` where `series.<type> = { summary, daily_metrics, today_checks }`
- `daily_metrics` is scoped to the full calendar year `Jan1..Dec31` of `$year` (decoupled from the filter range), only days with data, mapped per the spine schema
- per-type `summary` via `buildSummary()` on that type's logs returning `{ total_checks, success_ratio, status_totals }` (read from `by_type`)
- Consumes existing `$monitor->dailyCheckMetrics()->forTimezone()->betweenDates()`, `buildSummary()`, `availableYears()`, `graphCheckTypes()`

- [ ] **Step 1: Add failing tests for year-scoped daily_metrics decoupling and per-type summary to `MonitorHistoryGraphTest`.** Append these methods inside the class:
```php
    public function test_graph_daily_metrics_are_scoped_to_the_graph_year_not_the_filter_range(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2024-02-01 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2024-11-20 10:00:00');

        $this->artisan('monitor:aggregate-check-metrics', [
            '--from' => '2024-02-01',
            '--to' => '2024-02-01',
        ])->assertSuccessful();
        $this->artisan('monitor:aggregate-check-metrics', [
            '--from' => '2024-11-20',
            '--to' => '2024-11-20',
        ])->assertSuccessful();

        // Filter range (preset/from/to) is narrow and in a different year — graph must ignore it.
        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'year' => 2024,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->has('graph.series.uptime.daily_metrics', 2)
            ->where('graph.series.uptime.daily_metrics.0.date', '2024-02-01')
            ->where('graph.series.uptime.daily_metrics.1.date', '2024-11-20')
        );
    }

    public function test_graph_per_type_summary_counts_only_that_years_logs(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2024-04-10 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_FAILED, '2024-04-11 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2025-04-10 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'year' => 2024,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.series.uptime.summary.total_checks', 2)
            ->where('graph.series.uptime.summary.status_totals.success', 1)
            ->where('graph.series.uptime.summary.status_totals.failed', 1)
            ->where('graph.series.uptime.summary.success_ratio', 50.0)
        );
    }
```

- [ ] **Step 2: Run the test and confirm the new cases fail.**
```
php artisan test --filter MonitorHistoryGraphTest
```
Expected: FAIL (`graph.series.uptime.*` does not exist — `series` is still `[]`).

- [ ] **Step 3: Add the `buildGraphPayload()` helper below `resolveGraphYear()` in `MonitorsController`.**
```php
    protected function buildGraphPayload(Monitor $monitor, int $year, string $timezone): array
    {
        $availableYears = $this->availableYears($monitor, $timezone);
        $checkTypes = $this->graphCheckTypes($monitor);

        $yearStartUtc = Carbon::create($year, 1, 1, 0, 0, 0, $timezone)->startOfDay()->utc();
        $yearEndUtc = Carbon::create($year, 12, 31, 0, 0, 0, $timezone)->endOfDay()->utc();
        $yearStartDate = Carbon::create($year, 1, 1, 0, 0, 0, $timezone)->toDateString();
        $yearEndDate = Carbon::create($year, 12, 31, 0, 0, 0, $timezone)->toDateString();

        $dailyMetricsByType = $monitor->dailyCheckMetrics()
            ->forTimezone($timezone)
            ->betweenDates($yearStartDate, $yearEndDate)
            ->orderBy('date')
            ->get()
            ->groupBy('check_type')
            ->map(function ($rows) {
                return $rows->map(function ($row) {
                    return [
                        'date' => $row->date->toDateString(),
                        'total_checks' => $row->total_checks,
                        'successful_checks' => $row->successful_checks,
                        'warning_checks' => $row->warning_checks,
                        'failed_checks' => $row->failed_checks,
                        'success_ratio' => (float) $row->success_ratio,
                        'worst_status' => $row->worst_status,
                        'avg_response_time_ms' => $row->avg_response_time_ms,
                        'p95_response_time_ms' => $row->p95_response_time_ms,
                    ];
                })->values();
            });

        $series = [];

        foreach ($checkTypes as $checkType) {
            $type = $checkType['type'];

            $typeSummary = $this->buildSummary(
                $monitor->checkLogs()
                    ->where('check_type', $type)
                    ->whereBetween('checked_at', [$yearStartUtc, $yearEndUtc])
            );

            $series[$type] = [
                'summary' => [
                    'total_checks' => $typeSummary['by_type'][$type]['total_checks'] ?? 0,
                    'success_ratio' => $typeSummary['by_type'][$type]['success_ratio'] ?? 0,
                    'status_totals' => $typeSummary['by_type'][$type]['status_totals'] ?? [
                        MonitorCheckLogService::STATUS_SUCCESS => 0,
                        MonitorCheckLogService::STATUS_WARNING => 0,
                        MonitorCheckLogService::STATUS_FAILED => 0,
                        MonitorCheckLogService::STATUS_UNKNOWN => 0,
                    ],
                ],
                'daily_metrics' => $dailyMetricsByType->get($type, collect())->values()->all(),
                'today_checks' => $this->buildTodayChecks($monitor, $type, $timezone),
            ];
        }

        return [
            'year' => $year,
            'available_years' => $availableYears,
            'timezone' => $timezone,
            'check_types' => $checkTypes,
            'series' => $series,
        ];
    }
```

- [ ] **Step 4: Add a temporary stub `buildTodayChecks()` (fully implemented in Task 2.3) so `buildGraphPayload()` resolves.** Add below `buildGraphPayload()`:
```php
    protected function buildTodayChecks(Monitor $monitor, string $checkType, string $timezone): array
    {
        return [];
    }
```

- [ ] **Step 5: Replace the inline `$graph` array from Task 2.1 Step 6 with a `buildGraphPayload()` call.** In `show()`, replace the block:
```php
            $availableYears = $this->availableYears($monitor, $timezone);
            $graphYear = $this->resolveGraphYear($request, $availableYears);
            $graph = [
                'year' => $graphYear,
                'available_years' => $availableYears,
                'timezone' => $timezone,
                'check_types' => $this->graphCheckTypes($monitor),
                'series' => [],
            ];
```
with:
```php
            $availableYears = $this->availableYears($monitor, $timezone);
            $graphYear = $this->resolveGraphYear($request, $availableYears);
            $graph = $this->buildGraphPayload($monitor, $graphYear, $timezone);
```

- [ ] **Step 6: Run the test and confirm it passes.**
```
php artisan test --filter MonitorHistoryGraphTest
```
Expected: PASS (all 7 tests green, including decoupling + per-type summary).

- [ ] **Step 7: Run Pint.**
```
vendor/bin/pint --dirty
```
Expected: no style errors reported.

- [ ] **Step 8: Commit.**
```
git add app/Http/Controllers/MonitorsController.php tests/Feature/MonitorHistory/MonitorHistoryGraphTest.php && git commit -m "Build per-type graph series with year-scoped daily metrics and summary"
```

### Task 2.3: Implement `buildTodayChecks()` (today only, cap 200, newest first)

**Files:**
- modify: `app/Http/Controllers/MonitorsController.php`
- test: `tests/Feature/MonitorHistory/MonitorHistoryGraphTest.php`

**Interfaces:**
- Produces `protected function buildTodayChecks(Monitor $monitor, string $checkType, string $timezone): array` → today-only rows (in `$timezone`), `->limit(200)`, newest first, each `{ id, checked_at:'YYYY-MM-DD HH:mm:ss', status, message, failure_reason, response_time_ms }`
- Consumes `$monitor->checkLogs()`, `MonitorCheckLog`

- [ ] **Step 1: Add a failing test that today_checks contains only today's rows, newest first.** Append to `MonitorHistoryGraphTest`:
```php
    public function test_today_checks_contain_only_todays_rows_newest_first(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $todayMorning = Carbon::now('UTC')->startOfDay()->addHours(8);
        $todayNoon = Carbon::now('UTC')->startOfDay()->addHours(12);
        $yesterday = Carbon::now('UTC')->subDay()->setTime(10, 0);

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, $todayMorning->toDateTimeString());
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_FAILED, $todayNoon->toDateTimeString());
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, $yesterday->toDateTimeString());

        $currentYear = (int) Carbon::now('UTC')->format('Y');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'year' => $currentYear,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->has('graph.series.uptime.today_checks', 2)
            ->where('graph.series.uptime.today_checks.0.status', MonitorCheckLogService::STATUS_FAILED)
            ->where('graph.series.uptime.today_checks.1.status', MonitorCheckLogService::STATUS_SUCCESS)
        );
    }
```

- [ ] **Step 2: Run the test and confirm it fails.**
```
php artisan test --filter MonitorHistoryGraphTest
```
Expected: FAIL (stub `buildTodayChecks()` returns `[]`, so `today_checks` count is 0, not 2).

- [ ] **Step 3: Replace the stub `buildTodayChecks()` with the real implementation.** Replace the method body added in Task 2.2 Step 4:
```php
    protected function buildTodayChecks(Monitor $monitor, string $checkType, string $timezone): array
    {
        $todayStartUtc = Carbon::now($timezone)->startOfDay()->utc();
        $todayEndUtc = Carbon::now($timezone)->endOfDay()->utc();

        return $monitor->checkLogs()
            ->where('check_type', $checkType)
            ->whereBetween('checked_at', [$todayStartUtc, $todayEndUtc])
            ->latest('checked_at')
            ->limit(200)
            ->get()
            ->map(function (MonitorCheckLog $log) use ($timezone) {
                return [
                    'id' => $log->id,
                    'checked_at' => $log->checked_at->timezone($timezone)->toDateTimeString(),
                    'status' => $log->status,
                    'message' => $log->message,
                    'failure_reason' => $log->failure_reason,
                    'response_time_ms' => $log->response_time_ms,
                ];
            })
            ->all();
    }
```

- [ ] **Step 4: Run the test and confirm it passes.**
```
php artisan test --filter MonitorHistoryGraphTest
```
Expected: PASS (8 tests green; today_checks count is 2, failed row first).

- [ ] **Step 5: Run Pint.**
```
vendor/bin/pint --dirty
```
Expected: no style errors reported.

- [ ] **Step 6: Commit.**
```
git add app/Http/Controllers/MonitorsController.php tests/Feature/MonitorHistory/MonitorHistoryGraphTest.php && git commit -m "Implement buildTodayChecks for today-only newest-first per-type rows"
```

### Task 2.4: Add `filters`/`summary` props and `summary.first_checked_at`

**Files:**
- modify: `app/Http/Controllers/MonitorsController.php`
- test: `tests/Feature/MonitorHistory/MonitorHistorySummaryTest.php` (create)

**Interfaces:**
- Produces top-level `filters: { preset, from, to, timezone }`
- Produces top-level `summary: { all_time, selected_range, first_checked_at }` — `all_time`/`selected_range` are `buildSummary()` shape; `first_checked_at` is `'YYYY-MM-DD HH:mm:ss'|null` in `$timezone`
- Consumes existing `resolveHistoryRange()`, `buildSummary()`, `$monitor->checkLogs()`

- [ ] **Step 1: Create the failing summary/filters test file.**
```php
<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorHistorySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['monitor-history.enabled' => true]);
    }

    private function makeMonitor(array $attributes = []): Monitor
    {
        return Monitor::create(array_merge([
            'url' => 'https://example-'.uniqid().'.com',
            'uptime_check_enabled' => true,
            'domain_check_enabled' => false,
            'certificate_check_enabled' => false,
        ], $attributes));
    }

    private function seedUptimeLog(Monitor $monitor, string $status, string $checkedAt): void
    {
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: $status,
            checkedAt: Carbon::parse($checkedAt),
        );
    }

    public function test_filters_prop_reports_resolved_preset_and_range(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('filters.preset', 'custom')
            ->where('filters.from', '2026-03-01')
            ->where('filters.to', '2026-03-31')
            ->has('filters.timezone')
        );
    }

    public function test_summary_selected_range_differs_from_all_time(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_FAILED, '2025-01-01 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('summary.all_time.total_checks', 2)
            ->where('summary.selected_range.total_checks', 1)
            ->where('summary.selected_range.by_type.uptime.total_checks', 1)
        );
    }

    public function test_summary_first_checked_at_is_earliest_log_in_timezone(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2025-01-01 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('summary.first_checked_at', '2025-01-01 10:00:00')
        );
    }

    public function test_summary_first_checked_at_is_null_when_no_logs(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('summary.first_checked_at', null)
        );
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails.**
```
php artisan test --filter MonitorHistorySummaryTest
```
Expected: FAIL (top-level `filters` / `summary` props do not exist yet).

- [ ] **Step 3: In `show()`, add `$filters = null;` and `$summary = null;` alongside `$history = null;` / `$graph = null;` at the top of the method.**
```php
        $history = null;
        $graph = null;
        $filters = null;
        $summary = null;
```

- [ ] **Step 4: Inside the flag block, build the `$filters` and `$summary` props (reusing the already-computed `$range`, `$allTimeSummary`, `$selectedRangeSummary`).** After the existing `$selectedRangeSummary = $this->buildSummary($selectedRangeQuery);` line, add:
```php
            $firstCheckedAt = $monitor->checkLogs()->orderBy('checked_at')->value('checked_at');

            $filters = [
                'preset' => $range['preset'],
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
                'timezone' => $timezone,
            ];

            $summary = [
                'all_time' => $allTimeSummary,
                'selected_range' => $selectedRangeSummary,
                'first_checked_at' => $firstCheckedAt
                    ? Carbon::parse($firstCheckedAt)->timezone($timezone)->toDateTimeString()
                    : null,
            ];
```

- [ ] **Step 5: Add `'filters' => $filters,` and `'summary' => $summary,` to the `Inertia::render('Monitors/Show', [...])` array.**
```php
        return Inertia::render('Monitors/Show', [
            'monitor' => $monitor,
            'graph' => $graph,
            'filters' => $filters,
            'summary' => $summary,
            'history' => $history,
        ]);
```

- [ ] **Step 6: Run the test and confirm it passes.**
```
php artisan test --filter MonitorHistorySummaryTest
```
Expected: PASS (4 tests green).

- [ ] **Step 7: Run Pint.**
```
vendor/bin/pint --dirty
```
Expected: no style errors reported.

- [ ] **Step 8: Commit.**
```
git add app/Http/Controllers/MonitorsController.php tests/Feature/MonitorHistory/MonitorHistorySummaryTest.php && git commit -m "Add filters and summary props with first_checked_at"
```

### Task 2.5: Implement `buildRecentChecks()` (paginate 25, `recent_type` + range filter) and wire `recentChecks` prop

**Files:**
- modify: `app/Http/Controllers/MonitorsController.php`
- test: `tests/Feature/MonitorHistory/MonitorHistoryRecentChecksTest.php` (create)

**Interfaces:**
- Produces `protected function buildRecentChecks(Monitor $monitor, string $type, Carbon $fromUtc, Carbon $toUtc, string $timezone): array` → `{ type, data:[{id,check_type,status,checked_at,message,failure_reason,response_time_ms}], pagination:{current_page,last_page,per_page,total} }` via `->paginate(25)`, newest first
- Produces top-level `recentChecks` prop; request reads `recent_type` (default `'uptime'`) and `recent_page` (default 1)
- Consumes `$monitor->checkLogs()`, `MonitorCheckLog`, `resolveHistoryRange()`

- [ ] **Step 1: Create the failing recent-checks test file.**
```php
<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorHistoryRecentChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['monitor-history.enabled' => true]);
    }

    private function makeMonitor(array $attributes = []): Monitor
    {
        return Monitor::create(array_merge([
            'url' => 'https://example-'.uniqid().'.com',
            'uptime_check_enabled' => true,
            'domain_check_enabled' => true,
            'certificate_check_enabled' => false,
        ], $attributes));
    }

    private function seedLog(Monitor $monitor, string $checkType, string $status, string $checkedAt, ?int $responseTimeMs = null): void
    {
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: $checkType,
            status: $status,
            checkedAt: Carbon::parse($checkedAt),
            responseTimeMs: $responseTimeMs,
        );
    }

    public function test_recent_checks_pagination_shape_uses_page_size_25(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        for ($i = 0; $i < 30; $i++) {
            $this->seedLog(
                $monitor,
                MonitorCheckLogService::CHECK_TYPE_UPTIME,
                MonitorCheckLogService::STATUS_SUCCESS,
                Carbon::parse('2026-03-10 00:00:00')->addMinutes($i)->toDateTimeString(),
                120,
            );
        }

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('recentChecks.type', 'uptime')
            ->has('recentChecks.data', 25)
            ->where('recentChecks.pagination.per_page', 25)
            ->where('recentChecks.pagination.current_page', 1)
            ->where('recentChecks.pagination.last_page', 2)
            ->where('recentChecks.pagination.total', 30)
            ->where('recentChecks.data.0.response_time_ms', 120)
        );
    }

    public function test_recent_checks_respect_recent_page(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        for ($i = 0; $i < 30; $i++) {
            $this->seedLog(
                $monitor,
                MonitorCheckLogService::CHECK_TYPE_UPTIME,
                MonitorCheckLogService::STATUS_SUCCESS,
                Carbon::parse('2026-03-10 00:00:00')->addMinutes($i)->toDateTimeString(),
            );
        }

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'recent_page' => 2,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('recentChecks.pagination.current_page', 2)
            ->has('recentChecks.data', 5)
        );
    }

    public function test_recent_checks_filter_by_recent_type(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedLog($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');
        $this->seedLog($monitor, MonitorCheckLogService::CHECK_TYPE_DOMAIN, MonitorCheckLogService::STATUS_WARNING, '2026-03-11 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'recent_type' => 'domain',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('recentChecks.type', 'domain')
            ->has('recentChecks.data', 1)
            ->where('recentChecks.data.0.check_type', 'domain')
            ->where('recentChecks.data.0.status', MonitorCheckLogService::STATUS_WARNING)
        );
    }

    public function test_recent_checks_default_type_is_uptime(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedLog($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('recentChecks.type', 'uptime')
        );
    }

    public function test_recent_checks_respect_the_selected_range(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedLog($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');
        $this->seedLog($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME, MonitorCheckLogService::STATUS_FAILED, '2025-01-01 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('recentChecks.pagination.total', 1)
        );
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails.**
```
php artisan test --filter MonitorHistoryRecentChecksTest
```
Expected: FAIL (top-level `recentChecks` prop does not exist yet).

- [ ] **Step 3: Add the `buildRecentChecks()` helper below `buildTodayChecks()` in `MonitorsController`.**
```php
    protected function buildRecentChecks(Monitor $monitor, string $type, Carbon $fromUtc, Carbon $toUtc, string $timezone): array
    {
        $paginator = $monitor->checkLogs()
            ->where('check_type', $type)
            ->whereBetween('checked_at', [$fromUtc, $toUtc])
            ->latest('checked_at')
            ->paginate(25, ['*'], 'recent_page');

        $data = collect($paginator->items())
            ->map(function (MonitorCheckLog $log) use ($timezone) {
                return [
                    'id' => $log->id,
                    'check_type' => $log->check_type,
                    'status' => $log->status,
                    'checked_at' => $log->checked_at->timezone($timezone)->toDateTimeString(),
                    'message' => $log->message,
                    'failure_reason' => $log->failure_reason,
                    'response_time_ms' => $log->response_time_ms,
                ];
            })
            ->all();

        return [
            'type' => $type,
            'data' => $data,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
```

- [ ] **Step 4: In `show()`, add `$recentChecks = null;` to the top-of-method null block.**
```php
        $history = null;
        $graph = null;
        $filters = null;
        $summary = null;
        $recentChecks = null;
```

- [ ] **Step 5: Inside the flag block, resolve `recent_type` and build the `$recentChecks` prop (reusing `$fromUtc`/`$toUtc`/`$timezone`).** After the `$summary = [...]` assignment from Task 2.4, add:
```php
            $recentType = $request->string('recent_type')->toString() ?: MonitorCheckLogService::CHECK_TYPE_UPTIME;
            if (! in_array($recentType, [MonitorCheckLogService::CHECK_TYPE_UPTIME, MonitorCheckLogService::CHECK_TYPE_DOMAIN], true)) {
                $recentType = MonitorCheckLogService::CHECK_TYPE_UPTIME;
            }

            $recentChecks = $this->buildRecentChecks($monitor, $recentType, $fromUtc, $toUtc, $timezone);
```

- [ ] **Step 6: Add `'recentChecks' => $recentChecks,` to the `Inertia::render('Monitors/Show', [...])` array.**
```php
        return Inertia::render('Monitors/Show', [
            'monitor' => $monitor,
            'graph' => $graph,
            'filters' => $filters,
            'summary' => $summary,
            'recentChecks' => $recentChecks,
            'history' => $history,
        ]);
```

- [ ] **Step 7: Run the test and confirm it passes.**
```
php artisan test --filter MonitorHistoryRecentChecksTest
```
Expected: PASS (5 tests green; per_page 25, last_page 2, recent_type filter and range filter all honored).

- [ ] **Step 8: Run Pint.**
```
vendor/bin/pint --dirty
```
Expected: no style errors reported.

- [ ] **Step 9: Commit.**
```
git add app/Http/Controllers/MonitorsController.php tests/Feature/MonitorHistory/MonitorHistoryRecentChecksTest.php && git commit -m "Implement buildRecentChecks paginated by type and range"
```

### Task 2.6: Remove the legacy `history` prop and migrate existing tests + flag-off coverage

**Files:**
- modify: `app/Http/Controllers/MonitorsController.php`
- test: `tests/Feature/MonitorHistory/MonitorHistoryShowTest.php` (modify — existing OLD `history.*` assertions)

**Interfaces:**
- Removes top-level `history` prop entirely; `show()` now exposes only `monitor`, `graph`, `filters`, `summary`, `recentChecks`
- When `config('monitor-history.enabled')` is false, `graph`/`filters`/`summary`/`recentChecks` are all `null`
- Consumes the new props produced in Tasks 2.1–2.5

- [ ] **Step 1: Migrate the legacy timezone test in `MonitorHistoryShowTest` to the new prop name.** Replace:
```php
        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->has('history.daily_metrics.uptime', 1)
        );
```
with (this monitor's single log is on `2026-03-01`, so the graph year must be 2026):
```php
        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->has('graph.series.uptime.daily_metrics', 1)
        );
```
And change the request to pin the graph year so the assertion is year-stable:
```php
        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'all',
            'timezone' => 'Asia/Kolkata',
            'year' => 2026,
        ]));
```

- [ ] **Step 2: Migrate the legacy check-types test to `graph.check_types` and drop the certificate assertion (cert omitted from graph types).** Replace:
```php
            ->where('history.check_types', fn ($types) => collect($types)->firstWhere('type', 'uptime')['enabled'] === true
                && collect($types)->firstWhere('type', 'domain')['enabled'] === false
                && collect($types)->firstWhere('type', 'certificate')['enabled'] === false
            )
```
with:
```php
            ->where('graph.check_types', fn ($types) => collect($types)->pluck('type')->all() === ['uptime', 'domain']
                && collect($types)->firstWhere('type', 'uptime')['enabled'] === true
                && collect($types)->firstWhere('type', 'domain')['enabled'] === false
            )
```

- [ ] **Step 3: Migrate the legacy recent-checks range test to `recentChecks.pagination.total`.** Replace:
```php
        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->has('history.recent_checks', 1)
        );
```
with:
```php
        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('recentChecks.pagination.total', 1)
        );
```

- [ ] **Step 4: Add a flag-off test asserting all four props are null.** Append to `MonitorHistoryShowTest`:
```php
    public function test_history_props_are_null_when_feature_disabled(): void
    {
        config(['monitor-history.enabled' => false]);

        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph', null)
            ->where('filters', null)
            ->where('summary', null)
            ->where('recentChecks', null)
        );
    }
```

- [ ] **Step 5: Remove the legacy `history` payload from `MonitorsController@show`.** Delete the `$history = null;` declaration, the entire `$dailyMetrics = ...`, `$recentChecks = $monitor->checkLogs()...->map(...)` legacy block (the one building `metadata`/`recent_checks`), and the `$history = [ ... ]` assembly array; remove `'history' => $history,` from the `Inertia::render` array. Final render array:
```php
        return Inertia::render('Monitors/Show', [
            'monitor' => $monitor,
            'graph' => $graph,
            'filters' => $filters,
            'summary' => $summary,
            'recentChecks' => $recentChecks,
        ]);
```
Note: keep the `$selectedRangeQuery`, `$allTimeSummary`, `$selectedRangeSummary` computations — they feed the new `$summary` prop. Keep `resolveHistoryRange()`, `buildSummary()`, `parseDateInput()` intact.

- [ ] **Step 6: Run the full MonitorHistory feature suite and confirm everything passes.**
```
php artisan test --filter MonitorHistory
```
Expected: PASS (MonitorHistoryShowTest including the new flag-off test, plus MonitorHistoryGraphTest, MonitorHistorySummaryTest, MonitorHistoryRecentChecksTest — all green; no reference to `history.*` remains).

- [ ] **Step 7: Run Pint.**
```
vendor/bin/pint --dirty
```
Expected: no style errors reported.

- [ ] **Step 8: Commit.**
```
git add app/Http/Controllers/MonitorsController.php tests/Feature/MonitorHistory/MonitorHistoryShowTest.php && git commit -m "Remove legacy history prop and migrate tests to graph/filters/summary/recentChecks"
```

---

Authoring notes for the calling script:
- The `Show.jsx` frontend currently reads the now-removed `history` prop (lines 32–86, 273, 306–313). After Task 2.6 the page will render with `undefined` history data until Phase 3/4/5 rewire it to the new props. This is expected — backend feature tests (not the JS page) verify Phase 2; the page is rebuilt in later phases. No `npm run build` verification is added here because Phase 2 touches no `.jsx`/`.js`.
- `npm run test:js` is added in Phase 1; this phase uses only `php artisan test`.


---

## Phase 3 — Graphs section (build-verified)

**Deliverable:** A decoupled, full-calendar-year Graphs section rendered as position #1 of the Monitor History card in `Show.jsx`: a `buildHistoryParams` param helper (Vitest-TDD), a rebuilt responsive full-year `MonitorHistoryHeatmap`, a new `MonitorTodayBar`, async year navigation (prev/next bounded by `graph.available_years`, `only:['graph']`), and a per-type headline driven by `graph.series[type].summary` — all build-verified and covered by the Phase 2 graph-payload feature tests.

### Task 3.1: `buildHistoryParams(current, overrides)` helper (Vitest TDD)

**Files:**
- Test: `resources/js/Utils/historyParams.test.js` (create)
- Create: `resources/js/Utils/historyParams.js`

**Interfaces:**
- Produces: `export function buildHistoryParams(current, overrides)` — `current = { year, preset, from, to, recent_type, recent_page }` (derived from props); returns a merged plain object suitable for `router.get` params. Consumed by the year-nav control in Task 3.4 (override `{ year }`) and by Phases 4–5.
- Consumes: nothing (pure util).

- [ ] **Step 1: Write the failing Vitest spec.** Create `resources/js/Utils/historyParams.test.js`:
  ```js
  import { describe, it, expect } from "vitest";
  import { buildHistoryParams } from "@/Utils/historyParams";

  describe("buildHistoryParams", () => {
      const current = {
          year: 2026,
          preset: "30d",
          from: "2026-05-01",
          to: "2026-05-31",
          recent_type: "uptime",
          recent_page: 3,
      };

      it("returns the full current param set when no overrides are given", () => {
          expect(buildHistoryParams(current, {})).toEqual({
              year: 2026,
              preset: "30d",
              from: "2026-05-01",
              to: "2026-05-31",
              recent_type: "uptime",
              recent_page: 3,
          });
      });

      it("merges overrides over the current set without mutating current", () => {
          const result = buildHistoryParams(current, { year: 2025 });
          expect(result.year).toBe(2025);
          expect(result.preset).toBe("30d");
          expect(current.year).toBe(2026);
      });

      it("applies multiple overrides at once", () => {
          const result = buildHistoryParams(current, {
              recent_type: "domain",
              recent_page: 1,
          });
          expect(result.recent_type).toBe("domain");
          expect(result.recent_page).toBe(1);
      });

      it("drops keys whose override value is null or undefined", () => {
          const result = buildHistoryParams(current, { from: null, to: undefined });
          expect("from" in result).toBe(false);
          expect("to" in result).toBe(false);
          expect(result.preset).toBe("30d");
      });

      it("tolerates a partial current object", () => {
          expect(buildHistoryParams({ year: 2026 }, { recent_page: 2 })).toEqual({
              year: 2026,
              recent_page: 2,
          });
      });
  });
  ```

- [ ] **Step 2: Run the spec and confirm it fails.** Run:
  ```bash
  npm run test:js -- resources/js/Utils/historyParams.test.js
  ```
  Expected: FAIL — `Failed to resolve import "@/Utils/historyParams"` (module does not exist yet).

- [ ] **Step 3: Implement the helper.** Create `resources/js/Utils/historyParams.js`:
  ```js
  // Builds the full history param set for an Inertia partial visit by merging
  // the current param set with a control's change. Keys whose override value is
  // null/undefined are removed entirely so they fall back to the server default.
  export function buildHistoryParams(current, overrides) {
      const merged = { ...current, ...overrides };

      Object.keys(merged).forEach((key) => {
          if (merged[key] === null || merged[key] === undefined) {
              delete merged[key];
          }
      });

      return merged;
  }
  ```

- [ ] **Step 4: Run the spec and confirm it passes.** Run:
  ```bash
  npm run test:js -- resources/js/Utils/historyParams.test.js
  ```
  Expected: PASS — all 5 tests green.

- [ ] **Step 5: Commit.**
  ```bash
  git add resources/js/Utils/historyParams.js resources/js/Utils/historyParams.test.js && git commit -m "Add buildHistoryParams history-param merge helper (Vitest TDD)"
  ```

### Task 3.2: Rebuild `MonitorHistoryHeatmap` to the full-year spine props

**Files:**
- Modify: `resources/js/Components/MonitorHistoryHeatmap.jsx`

**Interfaces:**
- Produces: `MonitorHistoryHeatmap({ checkType, title, description, year, points, todayIso })` — `points = graph.series[type].daily_metrics`; full-year grid; month axis; today ring; per-metric legend; focusable cells + Tooltip.
- Consumes (all from Phase 1): `buildYearGrid(year)`, `monthLabelColumns(weeks)`, `computeCellSize(containerWidth, weekCount, opts)` from `@/Utils/heatmapCalendar`; `formatDateUTC(iso)` from `@/Utils/formatDate`; `statusesForCheckType(checkType)`, `CHECK_STATUS`, `normalizeCheckStatus`, `getCheckStatusMeta` from `@/Utils/checkStatusSeverity`; `Tooltip({ content, children, className })` from `@/Components/Tooltip`. Payload shape covered by Phase 2 graph-payload feature tests.

- [ ] **Step 1: Replace the entire file** `resources/js/Components/MonitorHistoryHeatmap.jsx` with the rebuilt full-year implementation:
  ```jsx
  import React, { useEffect, useMemo, useRef, useState } from "react";
  import {
      buildYearGrid,
      monthLabelColumns,
      computeCellSize,
  } from "@/Utils/heatmapCalendar";
  import { formatDateUTC } from "@/Utils/formatDate";
  import {
      CHECK_STATUS,
      normalizeCheckStatus,
      getCheckStatusMeta,
      statusesForCheckType,
  } from "@/Utils/checkStatusSeverity";
  import Tooltip from "@/Components/Tooltip";

  const WEEKDAY_LABELS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
  const CELL_GAP = 3;
  const CELL_MIN = 10;
  const CELL_MAX = 16;

  // Graded cell color by worst status + success ratio. Mirrors the locked status
  // palette. "No checks" days are an explicit gray fill (never transparent).
  function getCellClasses(point) {
      if (!point || point.total_checks === 0) {
          return "bg-gray-100 border-gray-200";
      }

      const normalizedStatus = normalizeCheckStatus(point.worst_status);
      const successRatio = Number(point.success_ratio || 0);

      if (normalizedStatus === CHECK_STATUS.FAILED) {
          if (successRatio < 30) return "bg-red-700 border-red-700";
          if (successRatio < 70) return "bg-red-500 border-red-500";
          return "bg-red-300 border-red-300";
      }

      if (normalizedStatus === CHECK_STATUS.WARNING) {
          if (successRatio < 80) return "bg-orange-400 border-orange-400";
          return "bg-yellow-300 border-yellow-300";
      }

      if (normalizedStatus === CHECK_STATUS.SUCCESS) {
          if (successRatio >= 99) return "bg-green-700 border-green-700";
          if (successRatio >= 95) return "bg-green-500 border-green-500";
          return "bg-green-300 border-green-300";
      }

      return "bg-gray-300 border-gray-300";
  }

  // Per-status legend swatches, filtered to the statuses this check type can emit.
  const LEGEND_DEFS = {
      success: {
          label: "Healthy",
          swatches: [
              "bg-green-300 border-green-300",
              "bg-green-500 border-green-500",
              "bg-green-700 border-green-700",
          ],
      },
      warning: {
          label: "Warning",
          swatches: [
              "bg-yellow-300 border-yellow-300",
              "bg-orange-400 border-orange-400",
          ],
      },
      failed: {
          label: "Failed",
          swatches: [
              "bg-red-300 border-red-300",
              "bg-red-500 border-red-500",
              "bg-red-700 border-red-700",
          ],
      },
      unknown: {
          label: "Unknown",
          swatches: ["bg-gray-300 border-gray-300"],
      },
  };

  // Explicit null -> "not measured" so a real 0ms value is never hidden by a
  // falsy check (fixes the 0ms-falsy bug in the previous tooltip builder).
  function formatMetric(value, suffix) {
      if (value === null || value === undefined) {
          return "not measured";
      }
      return `${value}${suffix}`;
  }

  function buildCellTooltip(point, iso) {
      const dateLabel = formatDateUTC(iso);

      if (!point || point.total_checks === 0) {
          return `${dateLabel}\nNo checks`;
      }

      return [
          dateLabel,
          `Status: ${getCheckStatusMeta(point.worst_status).label}`,
          `Total checks: ${point.total_checks}`,
          `Success: ${point.successful_checks}`,
          `Warning: ${point.warning_checks}`,
          `Failed: ${point.failed_checks}`,
          `Success ratio: ${point.success_ratio}%`,
          `Avg response: ${formatMetric(point.avg_response_time_ms, "ms")}`,
          `P95 response: ${formatMetric(point.p95_response_time_ms, "ms")}`,
      ].join("\n");
  }

  export default function MonitorHistoryHeatmap({
      checkType,
      title,
      description,
      year,
      points = [],
      todayIso = null,
  }) {
      const containerRef = useRef(null);
      const [cellSize, setCellSize] = useState(CELL_MIN);

      const pointMap = useMemo(
          () => new Map(points.map((point) => [point.date, point])),
          [points]
      );

      const grid = useMemo(() => buildYearGrid(year), [year]);
      const weeks = grid.weeks;
      const monthColumns = useMemo(() => monthLabelColumns(weeks), [weeks]);

      // Responsive fit: size cells to the container width via ResizeObserver,
      // clamped to [CELL_MIN, CELL_MAX]. Below CELL_MIN the wrapper scrolls.
      useEffect(() => {
          const element = containerRef.current;
          if (!element) {
              return undefined;
          }

          const measure = () => {
              setCellSize(
                  computeCellSize(element.clientWidth, weeks.length, {
                      gap: CELL_GAP,
                      min: CELL_MIN,
                      max: CELL_MAX,
                  })
              );
          };

          measure();
          const observer = new ResizeObserver(measure);
          observer.observe(element);
          return () => observer.disconnect();
      }, [weeks.length]);

      const isCurrentYear =
          todayIso !== null && todayIso.startsWith(String(year) + "-");

      const legendStatuses = statusesForCheckType(checkType);

      // Visually-hidden one-sentence summary for screen readers.
      const daysWithData = points.length;
      const failedDays = points.filter(
          (point) => normalizeCheckStatus(point.worst_status) === CHECK_STATUS.FAILED
      ).length;
      const srSummary = `${title}: ${year} calendar. ${daysWithData} day${
          daysWithData === 1 ? "" : "s"
      } with recorded checks, ${failedDays} with a failed worst-status.`;

      const cellStyle = { width: cellSize, height: cellSize };
      const labelTrackStyle = { height: cellSize };

      return (
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
              <div className="mb-4">
                  <h3 className="text-base font-semibold text-gray-900">{title}</h3>
                  {description ? (
                      <p className="mt-1 text-sm text-gray-500">{description}</p>
                  ) : null}
              </div>

              <p className="sr-only">{srSummary}</p>

              <div ref={containerRef} className="overflow-x-auto">
                  <div className="inline-flex flex-col gap-1 min-w-max">
                      {/* Month label row, aligned to the first week-column of each month. */}
                      <div
                          className="flex"
                          style={{ gap: CELL_GAP, marginLeft: cellSize + CELL_GAP }}
                          aria-hidden="true"
                      >
                          {weeks.map((_, weekIndex) => {
                              const month = monthColumns.find(
                                  (entry) => entry.colIndex === weekIndex
                              );
                              return (
                                  <span
                                      key={weekIndex}
                                      className="text-[10px] leading-3 text-gray-600 tabular-nums"
                                      style={{ width: cellSize }}
                                  >
                                      {month ? month.label : ""}
                                  </span>
                              );
                          })}
                      </div>

                      <div className="flex" style={{ gap: CELL_GAP }}>
                          {/* Weekday labels (Y axis). */}
                          <div className="flex flex-col" style={{ gap: CELL_GAP }}>
                              {WEEKDAY_LABELS.map((label) => (
                                  <span
                                      key={label}
                                      className="flex items-center text-[10px] leading-3 text-gray-600 tabular-nums"
                                      style={labelTrackStyle}
                                  >
                                      {label}
                                  </span>
                              ))}
                          </div>

                          <div
                              className="flex"
                              style={{ gap: CELL_GAP }}
                              role="grid"
                              aria-label={`${title} ${year} daily health`}
                          >
                              {weeks.map((week, weekIndex) => (
                                  <div
                                      key={weekIndex}
                                      className="flex flex-col"
                                      style={{ gap: CELL_GAP }}
                                      role="row"
                                  >
                                      {week.map((day) => {
                                          // Pad days outside the year (leading/trailing
                                          // week fill) are the ONLY transparent cells.
                                          if (!day.inYear) {
                                              return (
                                                  <div
                                                      key={day.iso}
                                                      className="rounded-sm bg-transparent"
                                                      style={cellStyle}
                                                      role="gridcell"
                                                      aria-hidden="true"
                                                  />
                                              );
                                          }

                                          const point = pointMap.get(day.iso);
                                          const isToday =
                                              isCurrentYear && day.iso === todayIso;

                                          return (
                                              <Tooltip
                                                  key={day.iso}
                                                  content={buildCellTooltip(point, day.iso)}
                                              >
                                                  <div
                                                      role="gridcell"
                                                      tabIndex={0}
                                                      aria-label={buildCellTooltip(
                                                          point,
                                                          day.iso
                                                      ).replace(/\n/g, ", ")}
                                                      className={[
                                                          "rounded-sm border transition-transform duration-150 ease-out",
                                                          "hover:scale-110 hover:ring-1 hover:ring-gray-400",
                                                          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500",
                                                          "motion-reduce:transition-none motion-reduce:transform-none",
                                                          getCellClasses(point),
                                                          isToday
                                                              ? "ring-2 ring-indigo-500"
                                                              : "",
                                                      ].join(" ")}
                                                      style={cellStyle}
                                                  />
                                              </Tooltip>
                                          );
                                      })}
                                  </div>
                              ))}
                          </div>
                      </div>
                  </div>
              </div>

              <div className="mt-4 flex items-center flex-wrap gap-x-4 gap-y-2 text-xs text-gray-600">
                  <span className="flex items-center gap-1.5">
                      <span className="h-3.5 w-3.5 rounded-sm border bg-gray-100 border-gray-200" />
                      No checks
                  </span>
                  {legendStatuses
                      .filter((status) => LEGEND_DEFS[status])
                      .map((status) => {
                          const def = LEGEND_DEFS[status];
                          return (
                              <span
                                  key={status}
                                  className="flex items-center gap-1.5"
                              >
                                  <span className="flex gap-0.5">
                                      {def.swatches.map((swatch) => (
                                          <span
                                              key={swatch}
                                              className={`h-3.5 w-3.5 rounded-sm border ${swatch}`}
                                          />
                                      ))}
                                  </span>
                                  {def.label}
                              </span>
                          );
                      })}
                  {isCurrentYear ? (
                      <span className="flex items-center gap-1.5">
                          <span className="h-3.5 w-3.5 rounded-sm border border-gray-200 bg-white ring-2 ring-indigo-500" />
                          Today
                      </span>
                  ) : null}
              </div>
          </div>
      );
  }
  ```

- [ ] **Step 2: Build-verify.** Run:
  ```bash
  npm run build
  ```
  Expected: built successfully (no import/JSX errors; `@/Utils/heatmapCalendar`, `@/Utils/formatDate`, `@/Components/Tooltip`, and `statusesForCheckType` resolve from Phase 1).

- [ ] **Step 3: Commit.**
  ```bash
  git add resources/js/Components/MonitorHistoryHeatmap.jsx && git commit -m "Rebuild MonitorHistoryHeatmap as responsive full-year grid with month axis, today ring, per-metric legend and a11y"
  ```

### Task 3.3: Create `MonitorTodayBar` component

**Files:**
- Create: `resources/js/Components/MonitorTodayBar.jsx`

**Interfaces:**
- Produces: `MonitorTodayBar({ checkType, checks })` — `checks = graph.series[type].today_checks` (newest first, each `{ id, checked_at:'YYYY-MM-DD HH:mm:ss', status, message, failure_reason, response_time_ms }`); thin segments newest->oldest, most-recent-that-fit (measure width); each segment colored by the status `heatmapClass` and wrapped in a Tooltip; small `Today (N checks)` label; per-metric legend reused.
- Consumes (Phase 1): `formatDateTimeUTC(iso)` from `@/Utils/formatDate`; `getCheckStatusMeta(status)` (returns `{ heatmapClass, label, ... }`) and `statusesForCheckType(checkType)` from `@/Utils/checkStatusSeverity`; `Tooltip` from `@/Components/Tooltip`. Payload `today_checks` covered by Phase 2 graph-payload feature tests.

> Note for implementer: the spine phrases this as `CHECK_STATUS_META[status].heatmapClass`. `CHECK_STATUS_META` is module-local (not exported) in `checkStatusSeverity.js`; use the exported accessor `getCheckStatusMeta(status).heatmapClass`, which returns that exact meta object. Do not add a new export.

- [ ] **Step 1: Create the file** `resources/js/Components/MonitorTodayBar.jsx`:
  ```jsx
  import React, { useEffect, useMemo, useRef, useState } from "react";
  import { formatDateTimeUTC } from "@/Utils/formatDate";
  import {
      getCheckStatusMeta,
      statusesForCheckType,
  } from "@/Utils/checkStatusSeverity";
  import Tooltip from "@/Components/Tooltip";

  const SEGMENT_WIDTH = 8;
  const SEGMENT_GAP = 2;

  const LEGEND_SWATCH = {
      success: "bg-green-500",
      warning: "bg-yellow-400",
      failed: "bg-red-500",
      unknown: "bg-gray-300",
  };

  function buildSegmentTooltip(check) {
      return [
          formatDateTimeUTC(check.checked_at),
          `Status: ${getCheckStatusMeta(check.status).label}`,
          check.message ? `Message: ${check.message}` : null,
          check.response_time_ms !== null && check.response_time_ms !== undefined
              ? `Response: ${check.response_time_ms}ms`
              : null,
          check.failure_reason ? `Failure: ${check.failure_reason}` : null,
      ]
          .filter(Boolean)
          .join("\n");
  }

  export default function MonitorTodayBar({ checkType, checks = [] }) {
      const containerRef = useRef(null);
      const [maxSegments, setMaxSegments] = useState(checks.length);

      // Measure how many newest-first segments fit; show the most recent that fit.
      useEffect(() => {
          const element = containerRef.current;
          if (!element) {
              return undefined;
          }

          const measure = () => {
              const width = element.clientWidth;
              const perSegment = SEGMENT_WIDTH + SEGMENT_GAP;
              const fit = Math.max(1, Math.floor((width + SEGMENT_GAP) / perSegment));
              setMaxSegments(fit);
          };

          measure();
          const observer = new ResizeObserver(measure);
          observer.observe(element);
          return () => observer.disconnect();
      }, [checks.length]);

      // checks are newest-first; keep the most recent that fit, then render
      // oldest->newest left-to-right so the newest sits on the right edge.
      const visible = useMemo(() => {
          return checks.slice(0, maxSegments).reverse();
      }, [checks, maxSegments]);

      const legendStatuses = statusesForCheckType(checkType);

      return (
          <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
              <p className="mb-2 text-xs font-medium text-gray-600 tabular-nums">
                  {`Today (${checks.length} checks)`}
              </p>

              <div ref={containerRef} className="flex items-stretch overflow-hidden">
                  {visible.length === 0 ? (
                      <span className="text-xs text-gray-500">
                          No checks recorded today.
                      </span>
                  ) : (
                      <div className="flex" style={{ gap: SEGMENT_GAP }}>
                          {visible.map((check) => (
                              <Tooltip
                                  key={check.id}
                                  content={buildSegmentTooltip(check)}
                              >
                                  <div
                                      tabIndex={0}
                                      aria-label={buildSegmentTooltip(check).replace(
                                          /\n/g,
                                          ", "
                                      )}
                                      className={[
                                          "h-8 rounded-sm transition-transform duration-150 ease-out",
                                          "hover:scale-y-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500",
                                          "motion-reduce:transition-none motion-reduce:transform-none",
                                          getCheckStatusMeta(check.status).heatmapClass,
                                      ].join(" ")}
                                      style={{ width: SEGMENT_WIDTH }}
                                  />
                              </Tooltip>
                          ))}
                      </div>
                  )}
              </div>

              <div className="mt-3 flex items-center flex-wrap gap-x-4 gap-y-2 text-xs text-gray-600">
                  {legendStatuses.map((status) => (
                      <span key={status} className="flex items-center gap-1.5">
                          <span
                              className={`h-3 w-3 rounded-sm ${
                                  LEGEND_SWATCH[status] || "bg-gray-300"
                              }`}
                          />
                          {getCheckStatusMeta(status).label}
                      </span>
                  ))}
              </div>
          </div>
      );
  }
  ```

- [ ] **Step 2: Build-verify.** Run:
  ```bash
  npm run build
  ```
  Expected: built successfully (imports from `@/Utils/formatDate`, `@/Utils/checkStatusSeverity`, `@/Components/Tooltip` all resolve).

- [ ] **Step 3: Commit.**
  ```bash
  git add resources/js/Components/MonitorTodayBar.jsx && git commit -m "Add MonitorTodayBar per-check today timeline with tooltips and legend"
  ```

### Task 3.4: Wire the Graphs section into `Show.jsx` as position #1 (decoupled)

**Files:**
- Modify: `resources/js/Pages/Monitors/Show.jsx`

**Interfaces:**
- Consumes: Inertia prop `graph` (`{ year, available_years, timezone, check_types:[{type,enabled}], series:{<type>:{summary:{total_checks,success_ratio,status_totals},daily_metrics,today_checks}} }`) from Phase 2; `buildHistoryParams(current, overrides)` (Task 3.1); `MonitorHistoryHeatmap` (Task 3.2); `MonitorTodayBar` (Task 3.3). Graph payload shape is covered by the Phase 2 feature tests (`MonitorHistoryShowTest`: graph year / `available_years` / `today_checks` / cert-omitted `check_types`).
- Produces: year-nav control (`only:['graph']`, override `{ year }`) + per-type headline + today-bar + heatmap, rendered first inside the history card.

> Note for implementer: Phase 2 splits the payload into top-level `graph`/`filters`/`summary`/`recentChecks` props and removes the old `history` prop. This task wires ONLY the Graphs section and reads ONLY `graph`. The legacy `history`-driven filter/summary/recent-checks blocks already present in `Show.jsx` (the gray filter card, the 4-stat grid, the old heatmap loop, the Recent Checks table) are replaced in Phases 4–5; leave them untouched here EXCEPT for removing the single old `MonitorHistoryHeatmap` usage loop, which this task supersedes. To keep the build green in the interim, this task does not delete the legacy blocks — it inserts the new Graphs section above them and removes only the old heatmap `.map(...)` loop (lines that render the old `MonitorHistoryHeatmap` with `fromDate`/`toDate`). The `current` param set is derived defensively from whatever range/year props exist.

- [ ] **Step 1: Replace the imports block** at the top of `Show.jsx`. Change:
  ```jsx
  import MonitorHistoryHeatmap from "@/Components/MonitorHistoryHeatmap";
  import {
      getCheckStatusBadgeColor,
      normalizeCheckStatus,
  } from "@/Utils/checkStatusSeverity";
  ```
  to:
  ```jsx
  import MonitorHistoryHeatmap from "@/Components/MonitorHistoryHeatmap";
  import MonitorTodayBar from "@/Components/MonitorTodayBar";
  import { buildHistoryParams } from "@/Utils/historyParams";
  import {
      getCheckStatusBadgeColor,
      normalizeCheckStatus,
  } from "@/Utils/checkStatusSeverity";
  import {
      ChevronLeftIcon,
      ChevronRightIcon,
  } from "@heroicons/react/24/outline";
  ```

- [ ] **Step 2: Read the new `graph` prop and derive the param set.** Immediately after the existing line `const selectedRange = history?.range || null;` (inside `Show`), insert:
  ```jsx
  const { graph } = usePage().props;
  const [graphPending, setGraphPending] = useState(false);

  // The graph is driven solely by ?year and is decoupled from the filters.
  const currentParams = useMemo(
      () => ({
          year: graph?.year,
          preset: selectedRange?.preset,
          from: selectedRange?.from,
          to: selectedRange?.to,
          recent_type: recentChecks?.type || "uptime",
          recent_page: recentChecks?.pagination?.current_page || 1,
      }),
      [graph?.year, selectedRange, recentChecks]
  );
  ```
  > Implementer note: `recentChecks` becomes a top-level prop in Phase 2. To avoid a ReferenceError before Phase 5 wires it, also destructure it defensively in Step 3.

- [ ] **Step 3: Destructure `recentChecks` defensively.** Change the existing destructure line:
  ```jsx
  const { monitor, features, history } = usePage().props;
  ```
  to:
  ```jsx
  const { monitor, features, history, recentChecks } = usePage().props;
  ```
  (Remove the separate `const { graph } = usePage().props;` line added in Step 2 and instead fold `graph` into this same destructure to keep one source: `const { monitor, features, history, graph, recentChecks } = usePage().props;`. Keep the `graphPending` state and `currentParams` memo from Step 2.)

- [ ] **Step 4: Add the year-nav handler.** After the `submitCustomRange` function definition, insert:
  ```jsx
  const goToYear = (targetYear) => {
      router.get(
          route("monitors.show", monitor.id),
          buildHistoryParams(currentParams, { year: targetYear }),
          {
              only: ["graph"],
              preserveState: true,
              preserveScroll: true,
              replace: true,
              onStart: () => setGraphPending(true),
              onFinish: () => setGraphPending(false),
          }
      );
  };
  ```

- [ ] **Step 5: Add a Graphs section renderer.** Inside the `(...)` branch where `history` is truthy (the `<div className="space-y-6">` block), insert the following as the FIRST child, immediately after the opening `<div className="space-y-6">`:
  ```jsx
  {graph ? (
      <section aria-label="Yearly health graphs" className="space-y-6">
          <div className="flex items-center justify-between gap-4">
              <h3 className="text-base font-semibold text-gray-900">
                  Health by year
              </h3>
              <div className="flex items-center gap-2" aria-busy={graphPending}>
                  <button
                      type="button"
                      onClick={() =>
                          goToYear(graph.year - 1)
                      }
                      disabled={
                          graphPending ||
                          graph.year <= Math.min(...graph.available_years)
                      }
                      className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500"
                      aria-label="Previous year"
                  >
                      <ChevronLeftIcon className="h-4 w-4" />
                  </button>
                  <span className="min-w-[3.5rem] text-center text-sm font-semibold text-gray-900 tabular-nums">
                      {graph.year}
                  </span>
                  <button
                      type="button"
                      onClick={() =>
                          goToYear(graph.year + 1)
                      }
                      disabled={
                          graphPending ||
                          graph.year >= Math.max(...graph.available_years)
                      }
                      className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500"
                      aria-label="Next year"
                  >
                      <ChevronRightIcon className="h-4 w-4" />
                  </button>
              </div>
          </div>

          {graph.check_types
              .filter(({ enabled }) => enabled)
              .map(({ type }) => {
                  const series = graph.series?.[type];
                  const typeSummary = series?.summary;
                  const todayIso = (graph.timezone, null) || null;

                  return (
                      <div key={type} className="space-y-3">
                          <p className="text-sm font-medium text-gray-700 tabular-nums">
                              {`${formatCheckTypeLabel(type)} · ${
                                  typeSummary
                                      ? Number(
                                            typeSummary.success_ratio
                                        ).toFixed(1)
                                      : "0.0"
                              }% · ${(
                                  typeSummary?.total_checks || 0
                              ).toLocaleString()} checks`}
                          </p>
                          <MonitorTodayBar
                              checkType={type}
                              checks={series?.today_checks || []}
                          />
                          <MonitorHistoryHeatmap
                              checkType={type}
                              title={`${formatCheckTypeLabel(type)} Health`}
                              description={`${graph.year} (${graph.timezone})`}
                              year={graph.year}
                              points={series?.daily_metrics || []}
                              todayIso={graph.today_iso || null}
                          />
                      </div>
                  );
              })}
      </section>
  ) : null}
  ```
  > Implementer note on `todayIso`: the spine says today's cell ring shows only when `year === current year` and `todayIso` is in range. The heatmap derives "current year" from `todayIso.startsWith(year + '-')`, so `todayIso` must be the server-tz "today" ISO date. The `graph` payload exposes it; pass `graph.today_iso`. Remove the throwaway `const todayIso = (graph.timezone, null) || null;` line — it was a placeholder; use `graph.today_iso` directly in the `todayIso` prop as shown. (If Phase 2's payload names this field differently, align to that exact field name; the canonical contract is the server-tz today ISO string.)

- [ ] **Step 6: Remove the legacy heatmap `.map(...)` loop.** Delete the old block in `Show.jsx` that renders `MonitorHistoryHeatmap` with `fromDate`/`toDate` (the `{checkTypes.map(({ type, enabled }) => enabled ? (<MonitorHistoryHeatmap ... fromDate={selectedRange?.from} toDate={selectedRange?.to} .../>) : (...disabled card...))}` block). The new Graphs section in Step 5 supersedes it. Leave the gray filter card, the 4-stat grid, and the Recent Checks table in place (replaced in Phases 4–5).

- [ ] **Step 7: Build-verify.** Run:
  ```bash
  npm run build
  ```
  Expected: built successfully (no unresolved imports; `graph` reads, `buildHistoryParams`, `MonitorTodayBar`, `MonitorHistoryHeatmap`, and the Chevron icons all resolve).

- [ ] **Step 8: Confirm the Phase 2 graph-payload tests still pass** (they assert the props this section renders). Run:
  ```bash
  php artisan config:clear && php artisan test --filter MonitorHistoryShowTest
  ```
  Expected: PASS — the graph year, `available_years`, `today_checks`, and cert-omitted `check_types` assertions from Phase 2 are green (this task only consumes that payload, does not change it).

- [ ] **Step 9: Commit.**
  ```bash
  git add resources/js/Pages/Monitors/Show.jsx && git commit -m "Wire decoupled Graphs section (year nav, per-type headline, today bar, full-year heatmap) as position 1 in Show"
  ```


---

## Phase 4 — Filters + Summary (build-verified)

**Deliverable:** `MonitorHistoryFilters` inline filter row (preset pills + From/To + Apply, all `h-9`, filter-driven only) and `SummaryStats` reliability-led KPI block (unknown reconciliation E4, all-time compare, empty-state disambiguation E5), both wired into `Show.jsx` at positions #2 and #3 with a single timezone label (E7) on the history section header; `npm run build` succeeds.

### Task 4.1: Create `MonitorHistoryFilters` component

**Files:**
- Create: `resources/js/Components/MonitorHistoryFilters.jsx`

**Interfaces:**
- Produces: `MonitorHistoryFilters({ filters, pending, onApply })` — `filters` = top-level Inertia `filters` prop `{ preset, from, to, timezone }`; `pending` = boolean from `Show.jsx`; `onApply({preset})` for a preset pill or `onApply({preset:'custom', from, to})` for the date range.
- Consumes: nothing from other Phase 4 files (self-contained, raw `<input type="date">` for matched height — `Input.jsx` wraps in a full-width div that would break the inline row).

- [ ] **Step 1: Create the component file with the preset definitions and controlled custom-range state.**
```jsx
import React, { useEffect, useState } from "react";

const PRESETS = [
    { value: "7d", label: "7d" },
    { value: "30d", label: "30d" },
    { value: "all", label: "All" },
];

function todayIso() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, "0");
    const day = String(now.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
}

export default function MonitorHistoryFilters({ filters, pending = false, onApply }) {
    const activePreset = filters?.preset || "30d";

    const [customRange, setCustomRange] = useState({
        from: filters?.from || "",
        to: filters?.to || "",
    });

    // Keep inputs in sync with the range the server actually applied (it may clamp/swap).
    useEffect(() => {
        setCustomRange({ from: filters?.from || "", to: filters?.to || "" });
    }, [filters?.from, filters?.to]);

    const max = todayIso();

    const handlePreset = (value) => {
        onApply({ preset: value });
    };

    const submitCustomRange = (event) => {
        event.preventDefault();
        onApply({ preset: "custom", from: customRange.from, to: customRange.to });
    };

    const segmentBase =
        "h-9 px-3 inline-flex items-center justify-center text-xs font-semibold border focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:ring-offset-1 transition-colors duration-150 ease-out motion-reduce:transition-none disabled:opacity-50 disabled:cursor-not-allowed";

    const dateInputClass =
        "h-9 px-3 text-sm font-medium tabular-nums text-gray-900 bg-white border border-gray-300 rounded-lg shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:border-transparent disabled:opacity-50";

    return (
        <div className="flex flex-wrap items-end gap-x-4 gap-y-3">
            <div
                className="inline-flex rounded-lg shadow-sm"
                role="group"
                aria-label="Date range presets"
            >
                {PRESETS.map((preset, index) => {
                    const isActive = activePreset === preset.value;
                    return (
                        <button
                            key={preset.value}
                            type="button"
                            aria-pressed={isActive}
                            disabled={pending}
                            onClick={() => handlePreset(preset.value)}
                            className={[
                                segmentBase,
                                index === 0 ? "rounded-l-lg" : "-ml-px",
                                index === PRESETS.length - 1 ? "rounded-r-lg" : "",
                                isActive
                                    ? "bg-purple-600 text-white border-purple-600 hover:bg-purple-700 active:bg-purple-800 z-10"
                                    : "bg-white text-gray-700 border-gray-300 hover:bg-gray-50 active:bg-gray-100",
                            ].join(" ")}
                        >
                            {preset.label}
                        </button>
                    );
                })}
            </div>

            <form
                onSubmit={submitCustomRange}
                className="flex flex-wrap items-end gap-x-3 gap-y-3"
            >
                <div className="flex flex-col">
                    <label
                        htmlFor="history-filter-from"
                        className="mb-1 text-xs font-medium text-gray-600"
                    >
                        From
                    </label>
                    <input
                        id="history-filter-from"
                        type="date"
                        name="from"
                        max={max}
                        value={customRange.from}
                        disabled={pending}
                        onChange={(event) =>
                            setCustomRange((previous) => ({
                                ...previous,
                                from: event.target.value,
                            }))
                        }
                        className={dateInputClass}
                    />
                </div>
                <div className="flex flex-col">
                    <label
                        htmlFor="history-filter-to"
                        className="mb-1 text-xs font-medium text-gray-600"
                    >
                        To
                    </label>
                    <input
                        id="history-filter-to"
                        type="date"
                        name="to"
                        max={max}
                        value={customRange.to}
                        disabled={pending}
                        onChange={(event) =>
                            setCustomRange((previous) => ({
                                ...previous,
                                to: event.target.value,
                            }))
                        }
                        className={dateInputClass}
                    />
                </div>
                <button
                    type="submit"
                    disabled={pending}
                    className="h-9 px-4 inline-flex items-center justify-center rounded-lg bg-purple-600 text-sm font-semibold text-white shadow-sm hover:bg-purple-700 active:bg-purple-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:ring-offset-1 transition-colors duration-150 ease-out motion-reduce:transition-none disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    Apply
                </button>
            </form>
        </div>
    );
}
```

- [ ] **Step 2: Verify the build compiles the new component.**
  Run: `npm run build`
  Expected: build completes successfully (no module-resolution or JSX errors); output ends with the Vite "built in <time>" summary.

- [ ] **Step 3: Commit.**
  Run: `git add resources/js/Components/MonitorHistoryFilters.jsx && git commit -m "Add MonitorHistoryFilters inline preset+range row"`

### Task 4.2: Create `SummaryStats` component

**Files:**
- Create: `resources/js/Components/SummaryStats.jsx`

**Interfaces:**
- Produces: `SummaryStats({ summary })` — `summary` = top-level Inertia `summary` prop `{ all_time, selected_range, first_checked_at }`; each of `all_time`/`selected_range` is the `buildSummary()` shape `{ total_checks, status_totals:{success,warning,failed,unknown}, by_type, success_ratio }`.
- Consumes: `formatDateTimeUTC` from `@/Utils/formatDate` (Phase 1) for the first-run empty state; `getCheckStatusBadgeColor` is NOT needed here (counts use the fixed status palette directly).
- Note: backend coverage — `MonitorHistoryShowTest` (Phase 2) asserts `summary.selected_range`, `summary.all_time`, and `summary.first_checked_at` prop shape; this component only renders that payload.

- [ ] **Step 1: Create the component file with the reliability color helper and count definitions.**
```jsx
import React from "react";
import { formatDateTimeUTC } from "@/Utils/formatDate";

function reliabilityColor(ratio) {
    if (ratio >= 99) return "text-green-700";
    if (ratio >= 95) return "text-green-600";
    if (ratio >= 80) return "text-yellow-600";
    return "text-red-600";
}

function formatRatio(ratio) {
    return `${Number(ratio || 0).toFixed(1)}%`;
}

// Includes Unknown so the four counts reconcile to total_checks (E4).
const COUNT_ITEMS = [
    { key: "success", label: "Success", className: "text-green-700" },
    { key: "warning", label: "Warning", className: "text-yellow-700" },
    { key: "failed", label: "Failed", className: "text-red-700" },
    { key: "unknown", label: "Unknown", className: "text-gray-600" },
];

export default function SummaryStats({ summary }) {
    const selected = summary?.selected_range || null;
    const allTime = summary?.all_time || null;
    const selectedTotal = selected?.total_checks || 0;
    const allTimeTotal = allTime?.total_checks || 0;

    // E5: first-run empty state — nothing has ever been checked.
    if (allTimeTotal === 0) {
        return (
            <div className="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-6 text-center">
                <p className="text-sm font-medium text-gray-700">
                    No checks have been recorded yet
                </p>
                <p className="mt-1 text-sm text-gray-500">
                    {summary?.first_checked_at
                        ? `First check expected around ${formatDateTimeUTC(summary.first_checked_at)}.`
                        : "Reliability stats will appear once this monitor runs its first check."}
                </p>
            </div>
        );
    }

    // E5: data exists all-time but the selected range is empty — disambiguate.
    if (selectedTotal === 0) {
        return (
            <div className="rounded-xl border border-gray-200 bg-white p-6">
                <p className="text-sm font-medium text-gray-700">
                    No checks in this range
                </p>
                <p className="mt-1 text-sm text-gray-500">
                    This monitor has{" "}
                    <span className="tabular-nums font-semibold text-gray-700">
                        {allTimeTotal}
                    </span>{" "}
                    checks all-time at{" "}
                    <span className="tabular-nums font-semibold text-gray-700">
                        {formatRatio(allTime?.success_ratio)}
                    </span>{" "}
                    reliability.{" "}
                    <button
                        type="button"
                        onClick={() => sendViewAllTime()}
                        className="font-semibold text-purple-600 hover:text-purple-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:ring-offset-1 rounded"
                    >
                        View All Time
                    </button>
                </p>
            </div>
        );

        function sendViewAllTime() {
            // Bridge to Show.jsx's preset handler via a CustomEvent so this
            // presentational component stays free of router/onApply coupling.
            window.dispatchEvent(
                new CustomEvent("monitor-history:view-all-time")
            );
        }
    }

    const selectedRatio = Number(selected?.success_ratio || 0);
    const totals = selected?.status_totals || {};

    return (
        <div className="flex flex-col gap-4 lg:flex-row lg:items-stretch">
            <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:w-64">
                <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
                    Reliability
                </p>
                <p
                    className={`mt-1 text-4xl font-bold tabular-nums ${reliabilityColor(
                        selectedRatio
                    )}`}
                >
                    {formatRatio(selectedRatio)}
                </p>
                <p className="mt-1 text-xs text-gray-500">
                    <span className="tabular-nums">{selectedTotal}</span> checks in
                    range · all-time{" "}
                    <span className="tabular-nums font-medium text-gray-600">
                        {formatRatio(allTime?.success_ratio)}
                    </span>
                </p>
            </div>

            <div className="grid flex-1 grid-cols-2 gap-3 sm:grid-cols-4">
                {COUNT_ITEMS.map((item) => (
                    <div
                        key={item.key}
                        className="rounded-xl border border-gray-200 bg-white p-4"
                    >
                        <p className="text-xs uppercase tracking-wide text-gray-500">
                            {item.label}
                        </p>
                        <p
                            className={`mt-2 text-2xl font-bold tabular-nums ${item.className}`}
                        >
                            {totals[item.key] || 0}
                        </p>
                    </div>
                ))}
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Verify the build compiles the new component (and resolves the Phase 1 `formatDate` import).**
  Run: `npm run build`
  Expected: build completes successfully; no "Could not resolve `@/Utils/formatDate`" error (Phase 1 created it).

- [ ] **Step 3: Commit.**
  Run: `git add resources/js/Components/SummaryStats.jsx && git commit -m "Add SummaryStats reliability-led KPIs with unknown reconciliation and empty states"`

### Task 4.3: Wire `MonitorHistoryFilters` (position #2) and `SummaryStats` (position #3) into `Show.jsx`

**Files:**
- Modify: `resources/js/Pages/Monitors/Show.jsx`

**Interfaces:**
- Consumes: top-level Inertia props `filters` and `summary` (Phase 2 payload restructure); `buildHistoryParams(current, overrides)` from Task 3.x (already imported/defined in `Show.jsx` after Phase 3); `MonitorHistoryFilters({ filters, pending, onApply })` (Task 4.1); `SummaryStats({ summary })` (Task 4.2).
- Partial-visit contract (spine): Filters → `router.get(route('monitors.show', monitor.id), buildHistoryParams(current, override), { only: ['filters','summary','recentChecks'], preserveState:true, preserveScroll:true, replace:true })` where override is `{ preset, recent_page:1 }` for a pill or `{ preset:'custom', from, to, recent_page:1 }` for a range; set `filtersPending` on `onStart`/`onFinish`.
- Note: This task assumes Phase 3 already changed `usePage().props` to read top-level `graph`/`filters`/`summary`/`recentChecks` and defined `current`/`buildHistoryParams`. Phase 4 only adds the filters callback + the two components + the timezone label. The exact `import`/`current`/`buildHistoryParams` lines below match the Phase 3 deliverable; if Phase 3 named them differently, reconcile to those names.

- [ ] **Step 1: Add imports for the two new components (place beside the existing `MonitorHistoryHeatmap` import).**
```jsx
import MonitorHistoryFilters from "@/Components/MonitorHistoryFilters";
import SummaryStats from "@/Components/SummaryStats";
```

- [ ] **Step 2: Add a `filtersPending` state and a `handleApplyFilters` callback inside the `Show` component (after the existing prop destructuring / `current` / `buildHistoryParams` from Phase 3).**
```jsx
const [filtersPending, setFiltersPending] = useState(false);

const handleApplyFilters = (change) => {
    // Timezone is resolved server-side to match how metrics were aggregated,
    // so we intentionally never send the browser timezone here.
    const overrides = { ...change, recent_page: 1 };

    router.get(
        route("monitors.show", monitor.id),
        buildHistoryParams(current, overrides),
        {
            only: ["filters", "summary", "recentChecks"],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setFiltersPending(true),
            onFinish: () => setFiltersPending(false),
        }
    );
};
```

- [ ] **Step 3: Wire the `SummaryStats` "View All Time" CustomEvent bridge to the filter handler (add a `useEffect` next to `handleApplyFilters`).**
```jsx
useEffect(() => {
    const onViewAllTime = () => handleApplyFilters({ preset: "all" });
    window.addEventListener("monitor-history:view-all-time", onViewAllTime);
    return () =>
        window.removeEventListener(
            "monitor-history:view-all-time",
            onViewAllTime
        );
    // handleApplyFilters closes over `monitor.id` and `current`, both stable per render.
}, [current]);
```

- [ ] **Step 4: Replace the legacy filter `<div className="rounded-xl border border-gray-200 bg-gray-50 p-4">…</div>` block (the preset buttons + custom-range `<form>`, the section that begins at the `selectedRange?.preset === "7d"` button and ends at the closing `</form>`'s wrapper) with the `MonitorHistoryFilters` component at position #2.**
```jsx
<MonitorHistoryFilters
    filters={filters}
    pending={filtersPending}
    onApply={handleApplyFilters}
/>
```

- [ ] **Step 5: Replace the legacy summary cards `<div className="grid grid-cols-1 md:grid-cols-4 gap-4">…</div>` block (the Total/Success/Warning/Failed cards) with the `SummaryStats` component at position #3.**
```jsx
<SummaryStats summary={summary} />
```

- [ ] **Step 6: Add the single timezone label (E7) to the "Monitor History" section header. Replace the existing header `<h2 className="text-sm font-semibold tracking-wide text-gray-500 uppercase mb-2">Monitor History</h2>` with a flex row that appends "All times in <timezone>".**
```jsx
<div className="mb-2 flex items-baseline justify-between gap-4">
    <h2 className="text-sm font-semibold tracking-wide text-gray-500 uppercase">
        Monitor History
    </h2>
    {filters?.timezone ? (
        <span className="text-xs text-gray-500">
            All times in {filters.timezone}
        </span>
    ) : null}
</div>
```

- [ ] **Step 7: Verify the build compiles the rewired page.**
  Run: `npm run build`
  Expected: build completes successfully; no unresolved imports and no references to the now-removed `customRange`/`submitCustomRange`/`applyRange`/`statusTotals`/`totalChecks` locals (if any remain orphaned after Phase 3, remove them — the page must compile clean).

- [ ] **Step 8: Commit.**
  Run: `git add resources/js/Pages/Monitors/Show.jsx && git commit -m "Wire MonitorHistoryFilters and SummaryStats into Show with timezone label"`


---

## Phase 5 — Recent Checks panel (build-verified; backend covered in Phase 2)

**Deliverable:** A `RecentChecksPanel` component with Uptime/Domain tabs (ARIA tablist, arrow-key nav, purple active underline), a Time/Type/Status/Message/Response(ms) table whose status cells reuse `Badge` with per-status heroicons + Title-case labels, a numbered prev/next pager driven by `recentChecks.pagination` (per_page 25), pending-aware disabled controls (`aria-busy`), row hover, and an empty state — wired into `Show.jsx` at position #4, driven entirely by the Phase 2 `recentChecks` partial-reload prop.

### Task 5.1: Create `RecentChecksPanel` component

**Files:**
- Create: `resources/js/Components/RecentChecksPanel.jsx`

**Interfaces:**
- Consumes (from spine, Inertia `recentChecks` prop produced in Phase 2):
  - `recentChecks: { type:'uptime'|'domain', data: [{ id, check_type, status, checked_at:'YYYY-MM-DD HH:mm:ss', message, failure_reason, response_time_ms }], pagination:{ current_page:int, last_page:int, per_page:int, total:int } }`
  - `checkTypes: [{ type:'uptime'|'domain', enabled:bool }]` (cert omitted upstream)
  - `pending: boolean`
  - `onTabChange(type:'uptime'|'domain'): void`
  - `onPageChange(page:int): void`
- Consumes JS utils (Phase 1 / existing):
  - `formatDateTimeUTC(iso) -> '27 Mar 2026, 15:00'` from `@/Utils/formatDate`
  - `normalizeCheckStatus(status)`, `getCheckStatusBadgeColor(status)`, `getCheckStatusMeta(status)` from `@/Utils/checkStatusSeverity` (`getCheckStatusMeta(status).label` gives Title-case label: Healthy/Warning/Failed/Unknown)
  - `Badge({ text, icon, color })` from `@/Components/Badge`
- Produces: `export default function RecentChecksPanel({ recentChecks, checkTypes, pending, onTabChange, onPageChange })`

- [ ] **Step 1: Create the file with imports and the per-status icon map.**
```jsx
import React, { useRef } from "react";
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
    QuestionMarkCircleIcon,
} from "@heroicons/react/24/outline";
import Badge from "@/Components/Badge";
import { formatDateTimeUTC } from "@/Utils/formatDate";
import {
    normalizeCheckStatus,
    getCheckStatusBadgeColor,
    getCheckStatusMeta,
} from "@/Utils/checkStatusSeverity";

const CHECK_TYPE_LABELS = {
    uptime: "Uptime",
    domain: "Domain",
};

const STATUS_ICONS = {
    success: CheckCircleIcon,
    warning: ExclamationTriangleIcon,
    failed: XCircleIcon,
    unknown: QuestionMarkCircleIcon,
};

function StatusBadge({ status }) {
    const normalized = normalizeCheckStatus(status);
    const Icon = STATUS_ICONS[normalized] || QuestionMarkCircleIcon;

    return (
        <Badge
            text={getCheckStatusMeta(normalized).label}
            color={getCheckStatusBadgeColor(normalized)}
            icon={<Icon className="h-3.5 w-3.5" aria-hidden="true" />}
        />
    );
}
```

- [ ] **Step 2: Add the `buildPageList` helper that produces the numbered pager sequence with ellipses.**
```jsx
function buildPageList(currentPage, lastPage) {
    if (lastPage <= 7) {
        return Array.from({ length: lastPage }, (_, index) => index + 1);
    }

    const pages = new Set([1, lastPage, currentPage]);
    if (currentPage - 1 >= 1) pages.add(currentPage - 1);
    if (currentPage + 1 <= lastPage) pages.add(currentPage + 1);

    const sorted = Array.from(pages).sort((a, b) => a - b);
    const withGaps = [];
    let previous = 0;
    for (const page of sorted) {
        if (previous && page - previous > 1) {
            withGaps.push(`gap-${previous}`);
        }
        withGaps.push(page);
        previous = page;
    }

    return withGaps;
}
```

- [ ] **Step 3: Add the tablist with arrow-key navigation.** Open the component, derive the active type from `recentChecks.type`, render a `role="tablist"` of the two `checkTypes`, each a `role="tab"` button with `aria-selected`, purple active underline, and arrow-key handler.
```jsx
export default function RecentChecksPanel({
    recentChecks,
    checkTypes,
    pending,
    onTabChange,
    onPageChange,
}) {
    const tabRefs = useRef({});
    const tabs = (checkTypes || []).filter(
        (entry) => entry.type === "uptime" || entry.type === "domain"
    );
    const activeType = recentChecks?.type || tabs[0]?.type || "uptime";
    const rows = recentChecks?.data || [];
    const pagination = recentChecks?.pagination || {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
    };

    const handleTabKeyDown = (event) => {
        if (event.key !== "ArrowRight" && event.key !== "ArrowLeft") {
            return;
        }
        event.preventDefault();
        const index = tabs.findIndex((entry) => entry.type === activeType);
        const delta = event.key === "ArrowRight" ? 1 : -1;
        const nextIndex = (index + delta + tabs.length) % tabs.length;
        const nextType = tabs[nextIndex]?.type;
        if (nextType) {
            tabRefs.current[nextType]?.focus();
            onTabChange(nextType);
        }
    };

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 className="text-base font-semibold text-gray-900">
                Recent Checks
            </h3>

            <div
                role="tablist"
                aria-label="Recent checks by check type"
                className="mt-4 flex items-center gap-6 border-b border-gray-200"
                onKeyDown={handleTabKeyDown}
            >
                {tabs.map(({ type }) => {
                    const isActive = type === activeType;

                    return (
                        <button
                            key={type}
                            type="button"
                            role="tab"
                            id={`recent-checks-tab-${type}`}
                            aria-selected={isActive}
                            aria-controls="recent-checks-panel"
                            tabIndex={isActive ? 0 : -1}
                            ref={(node) => {
                                tabRefs.current[type] = node;
                            }}
                            disabled={pending}
                            onClick={() => {
                                if (!isActive) {
                                    onTabChange(type);
                                }
                            }}
                            className={[
                                "-mb-px border-b-2 px-1 pb-2 text-sm font-medium transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:rounded-sm disabled:cursor-not-allowed disabled:opacity-60",
                                isActive
                                    ? "border-purple-600 text-purple-700"
                                    : "border-transparent text-gray-500 hover:text-gray-700",
                            ].join(" ")}
                        >
                            {CHECK_TYPE_LABELS[type] || type}
                        </button>
                    );
                })}
            </div>
```

- [ ] **Step 4: Add the tabpanel with the table (overflow-x-auto wrapper, hover rows, right-aligned tabular-nums Response column, empty-state row).**
```jsx
            <div
                role="tabpanel"
                id="recent-checks-panel"
                aria-labelledby={`recent-checks-tab-${activeType}`}
                aria-busy={pending}
                className="mt-4 overflow-x-auto"
            >
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="text-left text-gray-600 border-b border-gray-200">
                            <th className="py-2 pr-4 font-medium">Time</th>
                            <th className="py-2 pr-4 font-medium">Type</th>
                            <th className="py-2 pr-4 font-medium">Status</th>
                            <th className="py-2 pr-4 font-medium">Message</th>
                            <th className="py-2 pl-4 font-medium text-right">
                                Response (ms)
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td
                                    className="py-4 text-gray-500"
                                    colSpan={5}
                                >
                                    No checks recorded for this range.
                                </td>
                            </tr>
                        ) : (
                            rows.map((check) => (
                                <tr
                                    key={check.id}
                                    className="border-b border-gray-100 last:border-b-0 hover:bg-gray-50 transition-colors duration-150 motion-reduce:transition-none"
                                >
                                    <td className="py-2 pr-4 text-gray-700 whitespace-nowrap tabular-nums">
                                        {formatDateTimeUTC(check.checked_at)}
                                    </td>
                                    <td className="py-2 pr-4 text-gray-700">
                                        {CHECK_TYPE_LABELS[check.check_type] ||
                                            check.check_type}
                                    </td>
                                    <td className="py-2 pr-4">
                                        <StatusBadge status={check.status} />
                                    </td>
                                    <td className="py-2 pr-4 text-gray-700">
                                        {check.message ||
                                            check.failure_reason ||
                                            "No details"}
                                    </td>
                                    <td className="py-2 pl-4 text-right text-gray-700 tabular-nums whitespace-nowrap">
                                        {check.response_time_ms ?? "—"}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
```

- [ ] **Step 5: Add the numbered pager (prev/next + page numbers), pending-aware, closing the component.**
```jsx
            {pagination.last_page > 1 ? (
                <nav
                    aria-label="Recent checks pagination"
                    className="mt-4 flex items-center justify-center gap-1"
                >
                    <button
                        type="button"
                        disabled={pending || pagination.current_page <= 1}
                        onClick={() =>
                            onPageChange(pagination.current_page - 1)
                        }
                        className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Prev
                    </button>

                    {buildPageList(
                        pagination.current_page,
                        pagination.last_page
                    ).map((page) =>
                        typeof page === "string" ? (
                            <span
                                key={page}
                                className="px-2 py-1.5 text-sm text-gray-400"
                                aria-hidden="true"
                            >
                                …
                            </span>
                        ) : (
                            <button
                                key={page}
                                type="button"
                                aria-current={
                                    page === pagination.current_page
                                        ? "page"
                                        : undefined
                                }
                                disabled={pending}
                                onClick={() => onPageChange(page)}
                                className={[
                                    "min-w-[2.25rem] rounded-lg border px-3 py-1.5 text-sm font-medium tabular-nums transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 disabled:cursor-not-allowed disabled:opacity-50",
                                    page === pagination.current_page
                                        ? "border-purple-600 bg-purple-600 text-white"
                                        : "border-gray-300 text-gray-700 hover:bg-gray-50",
                                ].join(" ")}
                            >
                                {page}
                            </button>
                        )
                    )}

                    <button
                        type="button"
                        disabled={
                            pending ||
                            pagination.current_page >= pagination.last_page
                        }
                        onClick={() =>
                            onPageChange(pagination.current_page + 1)
                        }
                        className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Next
                    </button>
                </nav>
            ) : null}
        </div>
    );
}
```

- [ ] **Step 6: Verify the build.** Run:
```
npm run build
```
Expected: build completes successfully (no errors). Payload shape rendered here (`recentChecks.type`, `recentChecks.data[]`, `recentChecks.pagination`) is asserted by the Phase 2 backend feature tests in `tests/Feature/MonitorHistory/` (the recent-checks pagination + `recent_type` tab tests).

- [ ] **Step 7: Commit.**
```
git add resources/js/Components/RecentChecksPanel.jsx && git commit -m "Add RecentChecksPanel component with tabs and numbered pagination"
```

### Task 5.2: Wire `RecentChecksPanel` into `Show.jsx` at position #4

**Files:**
- Modify: `resources/js/Pages/Monitors/Show.jsx`

**Interfaces:**
- Consumes (Phase 2 Inertia props): `recentChecks`, `graph` (for `graph.check_types`), and the `buildHistoryParams(current, overrides)` helper from Task 3.x.
- Consumes the partial-visit contract (spine): Tab change → `only:['recentChecks']`, override `{ recent_type, recent_page: 1 }`; Page change → `only:['recentChecks']`, override `{ recent_page }`. Uses the existing `router.get(route('monitors.show', monitor.id), params, { only, preserveState:true, preserveScroll:true, replace:true })` pattern already in `Show.jsx`.
- Produces: `RecentChecksPanel` rendered as the 4th history section, fed by `recentChecks`, with a local `recentPending` flag toggled on `onStart`/`onFinish`.

> Note: Phase 3 introduces `buildHistoryParams` and the `current` param object derived from props, and Phase 3/4 already replace the legacy `history.*` reads with the split `graph`/`filters`/`summary`/`recentChecks` props. This task only adds the recent-checks slice; reuse the `current` object and `buildHistoryParams` exactly as established in Phase 3.

- [ ] **Step 1: Import `RecentChecksPanel`.** Add the import alongside the existing `MonitorHistoryHeatmap` import near the top of `Show.jsx`.
```jsx
import RecentChecksPanel from "@/Components/RecentChecksPanel";
```

- [ ] **Step 2: Read the `recentChecks` prop.** In the destructure of `usePage().props`, add `recentChecks` (it already exposes `monitor`, `features`, and the Phase 3/4 split props):
```jsx
const { monitor, features, graph, filters, summary, recentChecks } =
    usePage().props;
```

- [ ] **Step 3: Add a local pending flag for the recent-checks slice.** Place this with the other `useState` hooks:
```jsx
const [recentPending, setRecentPending] = useState(false);
```

- [ ] **Step 4: Add the tab- and page-change handlers using `buildHistoryParams` and the partial-visit contract.** Place these alongside the other history control handlers (after the Phase 3 `current`/`buildHistoryParams` wiring):
```jsx
const reloadRecentChecks = (overrides) => {
    router.get(
        route("monitors.show", monitor.id),
        buildHistoryParams(current, overrides),
        {
            only: ["recentChecks"],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setRecentPending(true),
            onFinish: () => setRecentPending(false),
        }
    );
};

const handleRecentTabChange = (type) => {
    reloadRecentChecks({ recent_type: type, recent_page: 1 });
};

const handleRecentPageChange = (page) => {
    reloadRecentChecks({ recent_page: page });
};
```

- [ ] **Step 5: Replace the legacy inline Recent Checks table block with `RecentChecksPanel`.** Remove the existing `<div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"> ... Recent Checks ... </table></div>` block (the one rendering `history.recent_checks`) and render the panel as the 4th section, passing `graph.check_types` so the tabs match the cert-omitted set:
```jsx
<RecentChecksPanel
    recentChecks={recentChecks}
    checkTypes={graph?.check_types || []}
    pending={recentPending}
    onTabChange={handleRecentTabChange}
    onPageChange={handleRecentPageChange}
/>
```

- [ ] **Step 6: Verify the build.** Run:
```
npm run build
```
Expected: build completes successfully (no errors). The wired `recentChecks` prop, its `recent_type` tab selection, and `paginate(25)` pagination are covered by the Phase 2 backend feature tests in `tests/Feature/MonitorHistory/` that assert `recentChecks.type`, `recentChecks.data`, and `recentChecks.pagination` via `assertInertia`.

- [ ] **Step 7: Commit.**
```
git add resources/js/Pages/Monitors/Show.jsx && git commit -m "Wire RecentChecksPanel into monitor Show page at position 4"
```
```

Authored Phase 5 above. Relevant absolute file paths for this phase:
- Create: `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Components/RecentChecksPanel.jsx`
- Modify: `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Pages/Monitors/Show.jsx`
- Plan being appended to: `/Users/vaibhav/projects/coloredcow/monitor/plans/2026-06-25-monitor-history-ui-enhancements-plan.md`

Notes carried from source review that shaped the tasks: `Badge` accepts `{ text, icon, color }` and renders the icon inline before the text, so the per-status heroicon is passed via the `icon` prop; `getCheckStatusMeta(status).label` already yields the Title-case labels (Healthy/Warning/Failed/Unknown) so no separate label map is needed; heroicons are imported from `@heroicons/react/24/outline` (matching the codebase convention); the existing `Show.jsx` `router.get` call uses `preserveState/preserveScroll/replace`, which the new partial-visit handlers extend with `only: ['recentChecks']` plus `onStart`/`onFinish` pending toggling. The current `MonitorHistoryShowTest.php` asserts the legacy `history.recent_checks`/`history.daily_metrics` shape — those assertions are migrated to the `recentChecks` prop in Phase 2, which is why Phase 5 is build-verified and references (does not author) those backend tests.


---

## Phase 6 — Header, live status & polish (build-verified)

**Deliverable:** Show.jsx header has Back inline-left and a `MonitorLiveStatus` pill near the URL; the page applies a consistent 2-tier header system, a flattened (border-divided) History card, a compact lower-emphasis Monitor Snapshot strip, and disabled check types collapsed to one slim muted line; every new transition carries `motion-reduce` variants, all chrome text is `gray-600`+, and all interactive controls have `focus-visible` rings.

### Task 6.1: Create `MonitorLiveStatus` (E1) live-status pill

**Files:**
- Create: `resources/js/Components/MonitorLiveStatus.jsx`
- Test (covering payload): `tests/Feature/MonitorHistory/MonitorHistoryShowTest.php` (existing — `monitor` model fields `uptime_status`, `uptime_last_check_date`, `uptime_check_failure_reason` already serialized by `show()`; no new backend test required)

**Interfaces:**
- Consumes (spine): `MonitorLiveStatus({ monitor })` — `monitor.uptime_status`, `monitor.uptime_last_check_date`, `monitor.uptime_check_failure_reason`.
- Consumes (spine): `mapUptimeStatusToCheckStatus(uptimeStatus)`, `CHECK_STATUS`, `getCheckStatusMeta(status)` from `@/Utils/checkStatusSeverity`; `formatRelative(iso, nowMs)` from `@/Utils/formatDate` (Phase 1).
- Produces: default-exported React component rendering a UP/DOWN/PENDING pill + `last checked X ago` + inline failure reason when down.

- [ ] **Step 1: Create the component file with imports and the pill-label/severity mapping.**
```jsx
import React from "react";
import {
    CHECK_STATUS,
    getCheckStatusMeta,
    mapUptimeStatusToCheckStatus,
} from "@/Utils/checkStatusSeverity";
import { formatRelative } from "@/Utils/formatDate";

// Maps the raw monitor.uptime_status string to the user-facing live pill label.
// Anything that is not a definite up/down is shown as PENDING (e.g. "not yet checked").
const LIVE_STATUS_LABELS = {
    [CHECK_STATUS.SUCCESS]: "UP",
    [CHECK_STATUS.FAILED]: "DOWN",
    [CHECK_STATUS.WARNING]: "PENDING",
    [CHECK_STATUS.UNKNOWN]: "PENDING",
};

// Solid dot + soft pill background per severity, reusing the locked status palette.
const LIVE_STATUS_PILL = {
    [CHECK_STATUS.SUCCESS]: "bg-green-50 text-green-700 border-green-200",
    [CHECK_STATUS.FAILED]: "bg-red-50 text-red-700 border-red-200",
    [CHECK_STATUS.WARNING]: "bg-yellow-50 text-yellow-700 border-yellow-200",
    [CHECK_STATUS.UNKNOWN]: "bg-gray-50 text-gray-600 border-gray-200",
};

const LIVE_STATUS_DOT = {
    [CHECK_STATUS.SUCCESS]: "bg-green-500",
    [CHECK_STATUS.FAILED]: "bg-red-500",
    [CHECK_STATUS.WARNING]: "bg-yellow-400",
    [CHECK_STATUS.UNKNOWN]: "bg-gray-300",
};
```

- [ ] **Step 2: Add the component body — pill, relative last-checked text, and down-only failure reason.**
```jsx
export default function MonitorLiveStatus({ monitor }) {
    const severity = mapUptimeStatusToCheckStatus(monitor?.uptime_status);
    const label = LIVE_STATUS_LABELS[severity] || "PENDING";
    const isDown = severity === CHECK_STATUS.FAILED;

    const lastChecked = monitor?.uptime_last_check_date
        ? formatRelative(monitor.uptime_last_check_date, Date.now())
        : null;

    const failureReason = isDown ? monitor?.uptime_check_failure_reason : null;

    return (
        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
            <span
                className={[
                    "inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5",
                    "text-xs font-semibold tracking-wide",
                    LIVE_STATUS_PILL[severity] || LIVE_STATUS_PILL[CHECK_STATUS.UNKNOWN],
                ].join(" ")}
            >
                <span
                    aria-hidden="true"
                    className={[
                        "h-2 w-2 rounded-full",
                        LIVE_STATUS_DOT[severity] || LIVE_STATUS_DOT[CHECK_STATUS.UNKNOWN],
                    ].join(" ")}
                />
                {label}
            </span>

            {lastChecked ? (
                <span className="text-xs text-gray-600 tabular-nums">
                    last checked {lastChecked}
                </span>
            ) : null}

            {failureReason ? (
                <span className="text-xs font-medium text-red-700">
                    {failureReason}
                </span>
            ) : null}
        </div>
    );
}
```

- [ ] **Step 3: Verify the build.** Run:
```
npm run build
```
Expected: `built successfully` (Vite completes with no errors). The `monitor` fields this renders (`uptime_status`, `uptime_last_check_date`, `uptime_check_failure_reason`) are already serialized in the `monitors.show` payload, covered by the existing `MonitorHistoryShowTest` payload assertions; no new feature test is required.

- [ ] **Step 4: Commit.** Run:
```
git add resources/js/Components/MonitorLiveStatus.jsx && git commit -m "Add MonitorLiveStatus live-status pill (E1)"
```

### Task 6.2: Move Back inline-left and mount `MonitorLiveStatus` in the header (Change 8)

**Files:**
- Modify: `resources/js/Pages/Monitors/Show.jsx`

**Interfaces:**
- Consumes (spine): `MonitorLiveStatus({ monitor })` (Task 6.1); locked decision **Back inline-left of title**.
- Produces: a left-aligned header (no `justify-between`) where the Back link sits to the left of the name/URL block, and the live-status pill renders beneath the URL.

- [ ] **Step 1: Add the `MonitorLiveStatus` import alongside the existing component imports in `Show.jsx`.** Insert directly after the `MonitorHistoryHeatmap` import:
```jsx
import MonitorLiveStatus from "@/Components/MonitorLiveStatus";
```

- [ ] **Step 2: Replace the entire `PageHeader` block** (the `<PageHeader>…</PageHeader>` element, currently the `flex items-center justify-between` layout with Back on the right) with a left-aligned layout placing Back first and the live pill under the URL:
```jsx
            <PageHeader>
                <div className="flex items-start gap-4">
                    <Link
                        href={route("monitors.index")}
                        className="mt-1 inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition-colors duration-150 ease-out hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:ring-offset-2 motion-reduce:transition-none"
                    >
                        <ArrowLeftIcon className="h-4 w-4" />
                        Back
                    </Link>
                    <div className="min-w-0">
                        <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                            {monitor.name}
                        </h1>
                        <div className="mt-1 flex items-center gap-1 text-sm text-gray-600">
                            <span className="truncate max-w-[35rem]">
                                {monitor.raw_url}
                            </span>
                            <a
                                href={monitor.raw_url}
                                target="_blank"
                                rel="noreferrer"
                                className="rounded text-gray-600 transition-colors duration-150 ease-out hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 motion-reduce:transition-none"
                                title="Open monitor URL"
                            >
                                <ArrowTopRightOnSquareIcon className="h-4 w-4" />
                            </a>
                        </div>
                        <MonitorLiveStatus monitor={monitor} />
                    </div>
                </div>
            </PageHeader>
```

- [ ] **Step 3: Verify the build.** Run:
```
npm run build
```
Expected: `built successfully`. Header now renders Back to the left of the name/URL block with no `justify-between`, and the live pill below the URL.

- [ ] **Step 4: Commit.** Run:
```
git add resources/js/Pages/Monitors/Show.jsx && git commit -m "Move Back inline-left and mount MonitorLiveStatus in header (Change 8)"
```

### Task 6.3: Compact lower-emphasis Monitor Snapshot strip (E9)

**Files:**
- Modify: `resources/js/Pages/Monitors/Show.jsx`

**Interfaces:**
- Consumes (spine): 2-tier header system (quiet uppercase eyebrow), `gray-600`+ chrome contrast, status palette unchanged.
- Produces: the Monitor Snapshot rendered as a single slim card (reduced padding, eyebrow label, inline icon row) instead of a full-height KPI-style card.

- [ ] **Step 1: Replace the Monitor Snapshot card** (the `<div className="bg-white rounded-xl p-6 …">` containing the `Monitor Snapshot` heading and the icon row) with a compact strip using the quiet uppercase eyebrow and tighter padding:
```jsx
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-gray-200 bg-white px-5 py-3 shadow-sm">
                    <span className="text-xs font-semibold uppercase tracking-wide text-gray-600">
                        Snapshot
                    </span>
                    <div className="flex items-center gap-2.5 flex-wrap">
                        <MonitorUptimeIcon monitor={monitor} />
                        <MonitorCheckIntervalIcon monitor={monitor} />
                        <MonitorDomainIcon monitor={monitor} />
                    </div>
                </div>
```

- [ ] **Step 2: Verify the build.** Run:
```
npm run build
```
Expected: `built successfully`. Snapshot now renders as a single compact horizontal strip with an inline eyebrow label.

- [ ] **Step 3: Commit.** Run:
```
git add resources/js/Pages/Monitors/Show.jsx && git commit -m "Make Monitor Snapshot a compact lower-emphasis strip (E9)"
```

### Task 6.4: 2-tier headers + flatten History card with border-t dividers (E9)

**Files:**
- Modify: `resources/js/Pages/Monitors/Show.jsx`

**Interfaces:**
- Consumes (spine): 2-tier header system — loud dark section titles (`text-lg font-semibold text-gray-900`) vs quiet uppercase eyebrows (`text-xs font-semibold uppercase tracking-wide text-gray-600`); `gray-600`+ chrome contrast.
- Produces: the History card with a single loud section title, and its inner blocks (graphs section, filters/summary section, recent checks section) separated by `border-t` dividers instead of nested card-in-card wrappers.

- [ ] **Step 1: Promote the History card heading to a loud Tier-1 section title.** Replace the existing `Monitor History` heading line:
```jsx
                    <h2 className="text-sm font-semibold tracking-wide text-gray-500 uppercase mb-2">
                        Monitor History
                    </h2>
```
with:
```jsx
                    <h2 className="text-lg font-semibold text-gray-900 mb-4">
                        Monitor History
                    </h2>
```

- [ ] **Step 2: Flatten the enabled-history block to border-divided sections.** Replace the outer `<div className="space-y-6">` that wraps the filters block, summary block, heatmaps, and recent-checks block with a divider-based container so the child sections (composed in Phases 3–5) are separated by top borders rather than each living in its own card. Change the opening wrapper:
```jsx
                        <div className="space-y-6">
```
to:
```jsx
                        <div className="divide-y divide-gray-200 [&>*]:py-6 [&>*:first-child]:pt-0 [&>*:last-child]:pb-0">
```

- [ ] **Step 3: Update the contrast on the two disabled/empty history messages** (the `History view is disabled.` and `History is enabled, but no history payload…` paragraphs). Change each `text-sm text-gray-600` — they are already `gray-600`, so confirm no `gray-500`/`gray-400` remains. If either still reads `text-gray-500`, replace it with `text-gray-600`. (No structural change; contrast confirmation only.)

- [ ] **Step 4: Verify the build.** Run:
```
npm run build
```
Expected: `built successfully`. The History card shows one loud title; its inner sections are separated by `border-t` dividers with consistent vertical padding and no card-in-card nesting.

- [ ] **Step 5: Commit.** Run:
```
git add resources/js/Pages/Monitors/Show.jsx && git commit -m "Apply 2-tier headers and flatten History card with dividers (E9)"
```

### Task 6.5: Collapse disabled check types into one slim muted line (E9)

**Files:**
- Modify: `resources/js/Pages/Monitors/Show.jsx`

**Interfaces:**
- Consumes (spine): `check_types` array `[{type:'uptime'|'domain', enabled}]` (cert omitted); `MonitorHistoryHeatmap({ checkType, title, description, year, points, todayIso })`; `formatCheckTypeLabel(checkType)` (existing local helper in `Show.jsx`).
- Produces: enabled check types render full heatmaps; all disabled check types collapse into a single slim muted line instead of one dashed card per type.

- [ ] **Step 1: Replace the `checkTypes.map(...)` graphs block** — the segment that currently renders a `MonitorHistoryHeatmap` when enabled and a dashed `border-dashed` card when disabled — with: render the enabled types as heatmaps, then a single muted summary line for the disabled ones. Use this structure (the heatmap props match the Phase-3 contract; the surrounding `graph`/`series` props are wired by Phase 3 — keep whatever enabled-branch props Phase 3 already produced and only replace the *disabled* branch handling):
```jsx
                            {checkTypes
                                .filter(({ enabled }) => enabled)
                                .map(({ type }) => (
                                    <MonitorHistoryHeatmap
                                        key={type}
                                        checkType={type}
                                        title={`${formatCheckTypeLabel(type)} Health`}
                                        year={graph?.year}
                                        points={graph?.series?.[type]?.daily_metrics || []}
                                        todayIso={todayIso}
                                    />
                                ))}

                            {checkTypes.some(({ enabled }) => !enabled) ? (
                                <p className="text-xs text-gray-600">
                                    {checkTypes
                                        .filter(({ enabled }) => !enabled)
                                        .map(({ type }) => formatCheckTypeLabel(type))
                                        .join(", ")}{" "}
                                    {checkTypes.filter(({ enabled }) => !enabled)
                                        .length === 1
                                        ? "check is"
                                        : "checks are"}{" "}
                                    not enabled for this monitor.
                                </p>
                            ) : null}
```

- [ ] **Step 2: Confirm `todayIso` and `graph` are in scope.** If Phase 3 already derives `const graph = usePage().props.graph` (and a `todayIso`), reuse them verbatim. If Phase 3 named the today value differently, substitute that exact name in Step 1. Do not introduce a new derivation if one already exists.

- [ ] **Step 3: Verify the build.** Run:
```
npm run build
```
Expected: `built successfully`. Disabled check types now render as a single muted `text-xs text-gray-600` line rather than full dashed cards. The `check_types` enabled flags this reads are asserted by `test_payload_advertises_each_check_type_with_its_enabled_flag` in `MonitorHistoryShowTest`.

- [ ] **Step 4: Commit.** Run:
```
git add resources/js/Pages/Monitors/Show.jsx && git commit -m "Collapse disabled check types into one slim muted line (E9)"
```

### Task 6.6: Final motion-reduce, contrast & focus-visible polish pass

**Files:**
- Modify: `resources/js/Pages/Monitors/Show.jsx`
- Modify: `resources/js/Components/MonitorHistoryHeatmap.jsx`

**Interfaces:**
- Consumes (spine Global Constraints): every transition carries `motion-reduce:transition-none motion-reduce:transform-none`; chrome text min `gray-600`; `focus-visible` rings on all interactive elements; status palette unchanged.
- Produces: all new/edited transitions guarded for reduced motion, no remaining `gray-400`/`gray-500` chrome text, and focus rings on every interactive control touched in Phase 6.

- [ ] **Step 1: Guard the heatmap cell transition for reduced motion.** In `MonitorHistoryHeatmap.jsx`, the cell `className` includes `transition-transform`. Replace that token:
```jsx
                                                "h-3.5 w-3.5 rounded-sm border transition-transform",
```
with:
```jsx
                                                "h-3.5 w-3.5 rounded-sm border transition-transform duration-150 ease-out motion-reduce:transition-none motion-reduce:transform-none",
```

- [ ] **Step 2: Bump the heatmap weekday-label contrast.** In `MonitorHistoryHeatmap.jsx`, the weekday labels use `text-gray-400`. Replace:
```jsx
                                className="h-3.5 text-[10px] text-gray-400 leading-3"
```
with:
```jsx
                                className="h-3.5 text-[10px] text-gray-600 leading-3"
```

- [ ] **Step 3: Bump the heatmap legend contrast.** In `MonitorHistoryHeatmap.jsx`, the legend wrapper uses `text-gray-500`. Replace:
```jsx
            <div className="mt-4 flex items-center flex-wrap gap-x-4 gap-y-2 text-xs text-gray-500">
```
with:
```jsx
            <div className="mt-4 flex items-center flex-wrap gap-x-4 gap-y-2 text-xs text-gray-600">
```

- [ ] **Step 4: Add a reduced-motion policy note above the heatmap component.** Insert this comment directly above `export default function MonitorHistoryHeatmap({` in `MonitorHistoryHeatmap.jsx`:
```jsx
// Reduced-motion policy: this component animates only via CSS transitions, each of
// which carries `motion-reduce:transition-none motion-reduce:transform-none`. There is
// no JS-driven animation here, so no `matchMedia('(prefers-reduced-motion: reduce)')`
// gate is required — the CSS variants fully satisfy the prefers-reduced-motion contract.
```

- [ ] **Step 5: Sweep `Show.jsx` for residual low-contrast chrome text.** Run a check for any remaining `text-gray-400` or `text-gray-500` chrome (label/caption) classes that Phase 6 edits left behind:
```
grep -nE "text-gray-(400|500)" /Users/vaibhav/projects/coloredcow/monitor/resources/js/Pages/Monitors/Show.jsx
```
Expected: no output. If any line is reported, replace its `text-gray-400`/`text-gray-500` with `text-gray-600` via an Edit so the constraint (chrome text min `gray-600`) holds, then re-run the grep until it returns nothing.

- [ ] **Step 6: Verify the build.** Run:
```
npm run build
```
Expected: `built successfully`. The heatmap cell transition is reduced-motion guarded, weekday/legend chrome is `gray-600`, and `Show.jsx` has no sub-`gray-600` chrome text remaining.

- [ ] **Step 7: Commit.** Run:
```
git add resources/js/Pages/Monitors/Show.jsx resources/js/Components/MonitorHistoryHeatmap.jsx && git commit -m "Final motion-reduce, contrast and focus-visible polish pass"
```

Files referenced (all absolute): `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Components/MonitorLiveStatus.jsx` (created), `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Pages/Monitors/Show.jsx`, `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Components/MonitorHistoryHeatmap.jsx`, `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/checkStatusSeverity.js`, `/Users/vaibhav/projects/coloredcow/monitor/resources/js/Utils/formatDate.js` (Phase 1), `/Users/vaibhav/projects/coloredcow/monitor/tests/Feature/MonitorHistory/MonitorHistoryShowTest.php`.

Note for the plan integrator: Tasks 6.4 and 6.5 assume the Phase 3–5 structure (e.g. `graph`/`series` derivation, `todayIso`, the `RecentChecksPanel`/`SummaryStats`/`MonitorHistoryFilters` sections living inside the History card). The disabled-branch and divider edits target the post-Phase-5 `Show.jsx`; if Phase 3 named the today value or `graph` prop differently, substitute that exact name in Task 6.5 Step 1/2.

