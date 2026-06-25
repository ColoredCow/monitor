# Monitor History — Recent-checks strip redesign + heatmap/tooltip polish (spec)

Rewritten from scratch. Branch: `feat/monitor-history-ui` (PR #81). Produced via superpowers brainstorming + the ui-ux-pro-max tool. Frontend + one small backend change. No change to the feature flag or the filter-driven `summary`/`recentChecks` table.

## Status / context

Round 1 of review fixes is already implemented and pushed (commits …`68d7936`): tooltip portal, per-metric heatmap tooltip, per-metric legend, **solid-indigo today cell**, heatmap hover-clip padding, prominent per-type headline stat, and single-solid-color domain/certificate cells.

Several round-2 reports are artifacts of a build not yet pulled/rebuilt, and are **already addressed** by round 1 — documented here for completeness, to be re-verified after rebuild (Node 22):

- The "shadow on hover" on a today bar (item 1) was the **old tooltip being clipped** by the strip's `overflow-hidden`; the round-1 Tooltip portal fix renders it to `<body>` instead. We additionally remove the bar's odd `hover:scale-y-110` grow (below).
- Tooltips on the strip bars (item 2) **already exist** (date+time via `formatDateTimeUTC`, status, message, response, failure) — they were just invisible behind `overflow-hidden`. Now visible via the portal.
- Today's heatmap cell showing green-with-a-ring (item 5) is pre-round-1; it is now **solid indigo**.

The genuinely new work this round is the **strip behavior (item 4)** plus a relabel and a color-consistency pass.

## Confirmed decisions (item 4 + related)

- **Window:** rolling **last 50 checks** per check type, **may span days**, newest on the right.
- **Capacity:** **responsive** — as many fixed-width slots as fit the container, capped at 50. Unfilled capacity is gray-padded on the **left**.
- **Refresh:** **static** — reflects data per page load / navigation. No background polling.
- **Today cell:** **solid indigo**, independent of the day's aggregate status (granular detail now lives in the strip).

## Design (UI/UX-informed)

The strip is a **status-timeline / per-event activity strip**. Each bar = one check, a **single solid color by status** (a single check has no ratio, so there is no gradient — true for every check type). Gray placeholder bars (the heatmap's "no-checks" gray, `bg-gray-100`) fill unused capacity on the left so the strip is always full-width; a brand-new monitor shows mostly gray with a few colored bars on the right. Newest bar sits on the far right. Per-bar tooltip on hover **and** keyboard focus; decorative gray bars are `aria-hidden` with no tooltip. (UI/UX guidance applied: tooltip-on-interact with exact values, color-not-alone via text/aria, meaningful empty state, keyboard reachability.)

## Changes

### A. Backend — last-50 strip data (`app/Http/Controllers/MonitorsController.php`)

- Rename `buildTodayChecks(Monitor $m, string $checkType, string $tz)` → `buildLatestChecks(Monitor $m, string $checkType, string $tz, int $limit = 50)`: `$m->checkLogs()->where('check_type', $checkType)->latest('checked_at')->limit($limit)->get()` mapped to the existing row shape `{id, checked_at (tz-converted 'Y-m-d H:i:s'), status, message, failure_reason, response_time_ms}`, newest-first. **No date bound** (was today-only).
- In `buildGraphPayload`, rename the series field `today_checks` → `latest_checks`.
- Update the Phase-2 graph feature test: assert `graph.series.<type>.latest_checks` is newest-first, capped at 50, and includes rows from prior days (no longer today-bounded). Keep all other graph assertions.

### B. Frontend — `MonitorTodayBar.jsx` → `MonitorRecentStrip.jsx`

- Rename the component + file; update the import + usage in `Show.jsx`; pass `checks={series?.latest_checks || []}`.
- **Capacity** = `min(50, slotsThatFit(containerWidth))` (fixed-width slots via ResizeObserver, as today).
- **Slot layout:** render `capacity` slots. The right-most `min(checks.length, capacity)` slots are the most-recent real checks (newest on the far right); the remaining left slots are gray placeholders. Extract the pure slot-building as `stripSlots(checks, capacity)` for unit testing (right-aligned real bars, gray-padded left).
- **Bar color:** shared single-status solid palette (see D) — consistent with domain/cert heatmap cells.
- **Tooltip:** keep the per-check tooltip (date+time, status, message, response **only for uptime / when non-null**, failure). Real bars: `tabIndex=0` + `aria-label` + Tooltip. Gray bars: `aria-hidden`, no tooltip, no focus.
- **Item 1 — remove the hover "shadow":** delete `hover:scale-y-110`; remove `overflow-hidden`; add small vertical padding (`py-0.5`) so the focus ring isn't clipped; keep the `focus-visible` ring; the only hover affordance is the tooltip (optional subtle `hover:opacity-80`, no layout shift).
- **Relabel:** "Today (N checks)" → "**Recent checks**" with a muted sub-label (e.g. "last {shown} checks"). No longer today-bound.
- **Legend:** unchanged in spirit — one swatch per `statusesForCheckType(checkType)` using the shared solid palette.

### C. Heatmap today cell (confirm — already implemented in round 1)

Today's cell = solid `bg-indigo-500 border-indigo-500`, overriding the day's status hue; legend "Today" swatch solid indigo; current-year only. Documented here; re-verify after rebuild.

### D. Shared single-status solid palette (item 3 — consistency)

One source of truth (extend `Utils/heatmapCell.js`): success `green-600`, warning `orange-400`, failed `red-600`, unknown `gray-400`. Used by **both** the domain/certificate heatmap cells **and** every recent-strip bar, so the two views agree. (Uptime heatmap cells keep their 3-shade ratio gradient — the strip is always single-color since each bar is one check.)

### Item 3 — "don't break the UI"

Cross-cutting: the strip and the (domain/cert) heatmap share the solid palette; the portal tooltip serves both; `npm run build` (Node 22) must pass; one review pass; visual check on monitor 20 (which has seeded recent + today data).

## Out of scope / deferred

- Live polling / auto-refresh of the strip (decision: static).
- Touch: tooltips appear on tap-driven focus; no dedicated tap-to-reveal layer this round.

## Verification

- **Vitest (TDD):** `stripSlots(checks, capacity)` (right-aligned real bars + gray left-pad; clamps to capacity); shared solid-palette mapping. Existing `heatmapCell` / util suites stay green.
- **Backend:** graph feature test updated for `latest_checks` (last-50, newest-first, spans days).
- **Build:** `npm run build` (Node 22) clean; one adversarial review; then `writing-plans` → implement.
