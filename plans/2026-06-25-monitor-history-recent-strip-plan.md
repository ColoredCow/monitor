# Monitor History — Recent-checks Strip Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the "Today" bar into a rolling **Recent checks** strip — the last 50 checks per type (may span days), newest on the right, gray-padded on the left — backed by a small last-50 backend change.

**Architecture:** Backend `buildGraphPayload` swaps its today-only `today_checks` series field for a `latest_checks` field (last 50 of that type, newest-first, no date bound). A pure `stripSlots(checks, capacity)` util computes the right-aligned + gray-padded slot array. The `MonitorTodayBar` component is renamed `MonitorRecentStrip`, rendering those slots with single-solid-color bars (shared palette) and a portal tooltip per real bar.

**Tech Stack:** Laravel 12, Inertia v2, React 19, Tailwind v4, PHPUnit 11 (MySQL `monitor_test`), Vitest (Node 22).

## Global Constraints

- Branch `feat/monitor-history-ui` (already checked out; PR #81). Commit per task.
- **JS tooling requires Node 22** — the default shell node is v12 and CANNOT run Vite 7 / Vitest 3. Prepend it to PATH in every npm command: `export PATH="/Users/vaibhav/.nvm/versions/node/v22.22.2/bin:$PATH" && npm run …` (a `node:fs/promises` ERR_UNKNOWN_BUILTIN_MODULE error = wrong node, not a code bug).
- **Backend tests:** MySQL `monitor_test`; run `php artisan config:clear` first; `php artisan test --filter <Name>`. Run `vendor/bin/pint --dirty` before committing PHP.
- **Frontend pure utils:** Vitest TDD (`npm run test:js`). **Components:** no DOM test runner — verify with `npm run build`.
- **Shared single-status solid palette** (already in `resources/js/Utils/heatmapCell.js` as `SINGLE_STATUS_CLASS`): success `bg-green-600 border-green-600`, warning `bg-orange-400 border-orange-400`, failed `bg-red-600 border-red-600`, unknown `bg-gray-400 border-gray-400`. The strip bars reuse it (consistency with domain/cert heatmap cells).
- Gray placeholder slot = `bg-gray-100 border-gray-200` (the heatmap "no checks" color).
- **Already implemented in round 1 (no task here; verify after build):** heatmap today cell = solid `bg-indigo-500`; tooltip portal (makes strip tooltips visible); per-metric heatmap tooltip/legend; headline stat.

## File Structure

- `app/Http/Controllers/MonitorsController.php` — rename `buildTodayChecks` → `buildLatestChecks` (last 50, no date bound); series field `today_checks` → `latest_checks` (Task 1).
- `tests/Feature/MonitorHistory/MonitorHistoryGraphTest.php` — update the today_checks test to latest_checks behavior (Task 1).
- `resources/js/Utils/recentStrip.js` + `resources/js/Utils/recentStrip.test.js` — pure `stripSlots` (Task 2).
- `resources/js/Components/MonitorRecentStrip.jsx` (renamed from `MonitorTodayBar.jsx`) — strip rendering (Task 3).
- `resources/js/Pages/Monitors/Show.jsx` — import/usage rename + `latest_checks` prop (Task 3).

## Interfaces

- `buildLatestChecks(Monitor $monitor, string $checkType, string $timezone, int $limit = 50): array` — newest-first rows `{id, checked_at:'Y-m-d H:i:s' (tz), status, message, failure_reason, response_time_ms}`. Graph series field: `series.<type>.latest_checks`.
- `stripSlots(checks, capacity)` — `checks` newest-first; returns an array of length `capacity`, left→right, with leading `null`s (gray placeholders) when `checks.length < capacity` and the most-recent checks trailing with the newest at the **last (right)** index.
- `MonitorRecentStrip({ checkType, checks })` — `checks` = `series[type].latest_checks` (newest-first).

---

### Task 1: Backend — `latest_checks` (last 50, spans days)

**Files:**
- Modify: `app/Http/Controllers/MonitorsController.php` (`buildTodayChecks`→`buildLatestChecks` at ~line 494; call site ~line 446)
- Test: `tests/Feature/MonitorHistory/MonitorHistoryGraphTest.php` (replace `test_today_checks_contain_only_todays_rows_newest_first`, ~lines 197–223)

**Interfaces:**
- Produces: `buildLatestChecks(Monitor, string $checkType, string $timezone, int $limit = 50): array`; graph series field `latest_checks`.
- Consumes: existing `$monitor->checkLogs()`, `MonitorCheckLog`.

- [ ] **Step 1: Replace the today_checks test with a latest_checks test.** In `MonitorHistoryGraphTest.php`, replace the whole `test_today_checks_contain_only_todays_rows_newest_first` method with:

```php
    public function test_latest_checks_are_newest_first_and_span_days(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $todayMorning = Carbon::now('UTC')->startOfDay()->addHours(8);
        $todayNoon = Carbon::now('UTC')->startOfDay()->addHours(12);
        $yesterday = Carbon::now('UTC')->subDay()->setTime(10, 0);

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, $todayMorning->toDateTimeString());
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_FAILED, $todayNoon->toDateTimeString());
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, $yesterday->toDateTimeString());

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'year' => (int) Carbon::now('UTC')->format('Y'),
        ]));

        // All three rows (incl. yesterday) — not today-bounded — newest first.
        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->has('graph.series.uptime.latest_checks', 3)
            ->where('graph.series.uptime.latest_checks.0.status', MonitorCheckLogService::STATUS_FAILED)
            ->where('graph.series.uptime.latest_checks.1.status', MonitorCheckLogService::STATUS_SUCCESS)
            ->where('graph.series.uptime.latest_checks.2.status', MonitorCheckLogService::STATUS_SUCCESS)
        );
    }

    public function test_latest_checks_are_capped_at_fifty(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $base = Carbon::now('UTC')->startOfDay()->addHours(1);
        for ($i = 0; $i < 60; $i++) {
            $this->seedUptimeLog(
                $monitor,
                MonitorCheckLogService::STATUS_SUCCESS,
                $base->copy()->addMinutes($i)->toDateTimeString()
            );
        }

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'year' => (int) Carbon::now('UTC')->format('Y'),
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->has('graph.series.uptime.latest_checks', 50)
        );
    }
```

- [ ] **Step 2: Run the tests — expect FAIL.**

Run: `php artisan config:clear && php artisan test --filter MonitorHistoryGraphTest`
Expected: FAIL — `graph.series.uptime.latest_checks` does not exist (the payload still has `today_checks`), and the span-days count would be 2 not 3.

- [ ] **Step 3: Rename the call site.** In `MonitorsController.php` `buildGraphPayload`, change the series line:

```php
                'latest_checks' => $this->buildLatestChecks($monitor, $type, $timezone),
```

- [ ] **Step 4: Replace `buildTodayChecks` with `buildLatestChecks`.** Replace the whole `buildTodayChecks(...)` method with:

```php
    protected function buildLatestChecks(Monitor $monitor, string $checkType, string $timezone, int $limit = 50): array
    {
        return $monitor->checkLogs()
            ->where('check_type', $checkType)
            ->latest('checked_at')
            ->limit($limit)
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

- [ ] **Step 5: Run the tests — expect PASS.**

Run: `php artisan test --filter MonitorHistoryGraphTest`
Expected: PASS (incl. the two new latest_checks tests). Then `php artisan test --filter MonitorHistory` — all green, no regressions.

- [ ] **Step 6: Pint + commit.**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/MonitorsController.php tests/Feature/MonitorHistory/MonitorHistoryGraphTest.php
git commit -m "Graph: latest_checks = last 50 per type (newest-first, spans days)"
```

---

### Task 2: `stripSlots` util (Vitest TDD)

**Files:**
- Create: `resources/js/Utils/recentStrip.js`
- Test: `resources/js/Utils/recentStrip.test.js`

**Interfaces:**
- Produces: `export function stripSlots(checks, capacity)` — `checks` newest-first; returns length-`capacity` array, left→right, leading `null`s when under-filled, most-recent checks trailing with the **newest at the last index**.

- [ ] **Step 1: Write the failing test.** Create `resources/js/Utils/recentStrip.test.js`:

```js
import { describe, it, expect } from "vitest";
import { stripSlots } from "@/Utils/recentStrip";

const c = (id) => ({ id });

describe("stripSlots", () => {
    it("returns all-gray (null) when there are no checks", () => {
        expect(stripSlots([], 3)).toEqual([null, null, null]);
    });

    it("right-aligns checks (newest last) and gray-pads the left", () => {
        // newest-first input: c3 newest, c1 oldest
        expect(stripSlots([c(3), c(2), c(1)], 5)).toEqual([
            null,
            null,
            c(1),
            c(2),
            c(3),
        ]);
    });

    it("fills exactly with no padding when checks === capacity", () => {
        expect(stripSlots([c(3), c(2), c(1)], 3)).toEqual([c(1), c(2), c(3)]);
    });

    it("keeps only the most-recent `capacity` checks (newest on the right)", () => {
        const five = [c(5), c(4), c(3), c(2), c(1)]; // newest-first
        expect(stripSlots(five, 3)).toEqual([c(3), c(4), c(5)]);
    });

    it("handles zero/negative capacity safely", () => {
        expect(stripSlots([c(1)], 0)).toEqual([]);
        expect(stripSlots([c(1)], -2)).toEqual([]);
    });
});
```

- [ ] **Step 2: Run — expect FAIL.**

Run: `export PATH="/Users/vaibhav/.nvm/versions/node/v22.22.2/bin:$PATH" && npm run test:js -- resources/js/Utils/recentStrip.test.js`
Expected: FAIL — module `@/Utils/recentStrip` not found.

- [ ] **Step 3: Implement.** Create `resources/js/Utils/recentStrip.js`:

```js
// Build the strip's slot array from a newest-first `checks` list.
// Returns an array of length `capacity` (left -> right): leading nulls are gray
// placeholders when there are fewer checks than slots; the most-recent checks
// trail with the NEWEST at the last (right-most) index.
export function stripSlots(checks, capacity) {
    const cap = Math.max(0, capacity);
    const recent = checks.slice(0, cap); // most-recent `cap`, still newest-first
    const ordered = recent.slice().reverse(); // oldest -> newest (newest last)
    const padCount = Math.max(0, cap - ordered.length);

    return [...Array(padCount).fill(null), ...ordered];
}
```

- [ ] **Step 4: Run — expect PASS.**

Run: `npm run test:js -- resources/js/Utils/recentStrip.test.js`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit.**

```bash
git add resources/js/Utils/recentStrip.js resources/js/Utils/recentStrip.test.js
git commit -m "Add stripSlots: right-aligned, gray-padded recent-check slots"
```

---

### Task 3: `MonitorRecentStrip` component + wire into Show

**Files:**
- Create: `resources/js/Components/MonitorRecentStrip.jsx` (renamed from `MonitorTodayBar.jsx`)
- Delete: `resources/js/Components/MonitorTodayBar.jsx`
- Modify: `resources/js/Pages/Monitors/Show.jsx` (import + usage + prop)

**Interfaces:**
- Consumes: `stripSlots` (Task 2); `SINGLE_STATUS_CLASS` (`@/Utils/heatmapCell`); `formatDateTimeUTC`, `getCheckStatusMeta`, `normalizeCheckStatus`, `statusesForCheckType` (`@/Utils/checkStatusSeverity`); `Tooltip`; `series.<type>.latest_checks` (Task 1).
- Produces: `MonitorRecentStrip({ checkType, checks })`.

- [ ] **Step 1: Create `resources/js/Components/MonitorRecentStrip.jsx`** with the full content:

```jsx
import React, { useEffect, useMemo, useRef, useState } from "react";
import { formatDateTimeUTC } from "@/Utils/formatDate";
import {
    getCheckStatusMeta,
    normalizeCheckStatus,
    statusesForCheckType,
} from "@/Utils/checkStatusSeverity";
import { SINGLE_STATUS_CLASS } from "@/Utils/heatmapCell";
import { stripSlots } from "@/Utils/recentStrip";
import Tooltip from "@/Components/Tooltip";

const SEGMENT_WIDTH = 8;
const SEGMENT_GAP = 2;
const MAX_SLOTS = 50;

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

export default function MonitorRecentStrip({ checkType, checks = [] }) {
    const containerRef = useRef(null);
    const [capacity, setCapacity] = useState(MAX_SLOTS);

    // How many fixed-width slots fit, capped at MAX_SLOTS.
    useEffect(() => {
        const element = containerRef.current;
        if (!element) {
            return undefined;
        }

        const measure = () => {
            const width = element.clientWidth;
            const perSegment = SEGMENT_WIDTH + SEGMENT_GAP;
            const fit = Math.max(
                1,
                Math.floor((width + SEGMENT_GAP) / perSegment)
            );
            setCapacity(Math.min(MAX_SLOTS, fit));
        };

        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(element);
        return () => observer.disconnect();
    }, [checks.length]);

    const slots = useMemo(
        () => stripSlots(checks, capacity),
        [checks, capacity]
    );
    const shown = slots.filter(Boolean).length;
    const legendStatuses = statusesForCheckType(checkType);

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div className="mb-2 flex items-baseline gap-x-2">
                <span className="text-sm font-medium text-gray-900">
                    Recent checks
                </span>
                <span className="text-xs text-gray-500 tabular-nums">
                    last {shown}
                </span>
            </div>

            <div ref={containerRef} className="flex items-stretch py-0.5">
                <div className="flex" style={{ gap: SEGMENT_GAP }}>
                    {slots.map((check, index) =>
                        check ? (
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
                                        "h-8 rounded-sm border transition-opacity duration-150 ease-out",
                                        "hover:opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500",
                                        "motion-reduce:transition-none",
                                        SINGLE_STATUS_CLASS[
                                            normalizeCheckStatus(check.status)
                                        ],
                                    ].join(" ")}
                                    style={{ width: SEGMENT_WIDTH }}
                                />
                            </Tooltip>
                        ) : (
                            <div
                                key={`gap-${index}`}
                                aria-hidden="true"
                                className="h-8 rounded-sm border bg-gray-100 border-gray-200"
                                style={{ width: SEGMENT_WIDTH }}
                            />
                        )
                    )}
                </div>
            </div>

            <div className="mt-3 flex items-center flex-wrap gap-x-4 gap-y-2 text-xs text-gray-600">
                {legendStatuses.map((status) => (
                    <span key={status} className="flex items-center gap-1.5">
                        <span
                            className={`h-3 w-3 rounded-sm border ${SINGLE_STATUS_CLASS[status]}`}
                        />
                        {getCheckStatusMeta(status).label}
                    </span>
                ))}
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Delete the old component.**

```bash
git rm resources/js/Components/MonitorTodayBar.jsx
```

- [ ] **Step 3: Update `Show.jsx` import.** Replace `import MonitorTodayBar from "@/Components/MonitorTodayBar";` with:

```jsx
import MonitorRecentStrip from "@/Components/MonitorRecentStrip";
```

- [ ] **Step 4: Update the usage in `Show.jsx`.** Replace the `<MonitorTodayBar … />` element with:

```jsx
                                                    <MonitorRecentStrip
                                                        checkType={type}
                                                        checks={series?.latest_checks || []}
                                                    />
```

- [ ] **Step 5: Build — expect success.**

Run: `export PATH="/Users/vaibhav/.nvm/versions/node/v22.22.2/bin:$PATH" && npm run build`
Expected: built successfully, no errors, no remaining reference to `MonitorTodayBar` (grep: `grep -rn "MonitorTodayBar\|today_checks" resources/js` returns nothing).

- [ ] **Step 6: Commit.**

```bash
git add resources/js/Components/MonitorRecentStrip.jsx resources/js/Pages/Monitors/Show.jsx
git commit -m "Recent strip: rolling last-50 bars, gray-padded, single-color, no hover-grow"
```

---

## Self-Review

1. **Spec coverage:** Item 4 (rolling last-50, responsive cap-50, gray-pad left, static) → Tasks 1+2+3. Item 1 (remove hover shadow/grow) → Task 3 (drop `hover:scale-y-110` + `overflow-hidden`). Item 2 (tooltip date+time+details) → Task 3 (`buildSegmentTooltip` kept) + round-1 portal. Item 3 (consistency) → shared `SINGLE_STATUS_CLASS` in Task 3 + backend in Task 1. Item 5 (today solid indigo) → already shipped round 1 (verify after build). Relabel → Task 3.
2. **Placeholder scan:** none — every step has exact code/commands.
3. **Type consistency:** `buildLatestChecks` / `latest_checks` / `stripSlots` / `SINGLE_STATUS_CLASS` / `MonitorRecentStrip({checkType, checks})` used consistently across tasks.
