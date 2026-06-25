# Monitor History UI — review-feedback fixes (follow-up to PR #81)

Branch: `feat/monitor-history-ui` (PR #81). All frontend; no backend/payload change.

Confirmed decisions: solid-indigo Today (overrides today's health hue); domain & certificate use ONE solid color per status (cells + legend), gradient kept ONLY for uptime; per-metric tooltip (uptime = + latency when present; domain/cert = no latency, status rows filtered to reachable statuses).

Core insight: ratio-based shading + latency/ratio tooltip metrics are meaningful only for high-frequency **uptime** (many checks/day). **Domain & certificate are once-daily single checks** → single solid color per status, slimmer tooltip.

## Fixes

1. **Tooltip clipping + width** (`Tooltip.jsx`). Render via React portal to `document.body` with fixed positioning computed from the trigger's `getBoundingClientRect()` (escapes the heatmap's `overflow-x-auto` clip). Position above-centered, flip below / clamp horizontally near viewport edges. Width: `w-max max-w-xs` so lines don't wrap into a thin column. Keep the `{content, children, className}` API (MonitorTodayBar reuses it). Hide on scroll/blur/mouseleave.

2. **Per-metric tooltip** (`Utils/heatmapCell.js` + heatmap). `cellMetricLines(point, checkType)`: Status, Total, then only the status-count rows for `statusesForCheckType(checkType)` (uptime: Success/Failed — no Warning; domain: Success/Warning/Failed; cert: Success/Failed), Success ratio; avg/p95 **only for uptime and only when non-null** (omitted, not "not measured"; dropped entirely for domain/cert).

3. **Solid-indigo Today** (heatmap). Today's cell → solid `bg-indigo-500 border-indigo-500` fill (replaces status fill + ring). Legend "Today" swatch → solid `bg-indigo-500` (was a ringed white box). Current-year-only, unchanged.

5. **Saturday hover clip** (heatmap). Add vertical/horizontal padding inside the `overflow-x-auto` scroll container (e.g. `p-1`) so the bottom-row hover `scale-110` + ring isn't clipped (can't use overflow-y:visible alongside overflow-x:auto).

6. **Headline prominence** (`Show.jsx`). Restyle the per-type headline ("Uptime · 100.0% · 1,399 checks") from `text-sm font-medium` body text into a compact stat: % as `text-base font-semibold text-gray-900 tabular-nums`, label + "N checks" smaller/muted. Subtle, not large.

7. **Single solid color for domain/cert** (`Utils/heatmapCell.js` + heatmap legend). `cellColorClass(point, checkType)`: uptime → existing graded 3-shade; domain/cert → single solid per worst status (success `green-600`, warning `orange-400`, failed `red-600`, unknown `gray-400`), no-data `gray-100`. Legend per-metric: uptime → 3-swatch gradient; domain/cert → one swatch per applicable status (matching the single colors).

(Item 4 — seed monitor 20 today data — already done.)

## Verification
- New `Utils/heatmapCell.js` pure functions (`cellColorClass`, `cellMetricLines`) covered by Vitest (TDD): uptime graded vs domain single-color; uptime tooltip omits Warning row + includes latency when present; domain/cert tooltip omits latency.
- `npm run build` (Node 22) clean; existing Vitest suite green.
- One adversarial review pass over the diff; then commit + push to PR #81.
