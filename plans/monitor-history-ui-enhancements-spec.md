# Monitor History — UI Enhancements Spec

Status: **DRAFT for review** (no code written yet). Source of requirements: `UI_CHANGES_LOG.md` (changes 1–8) + UI/UX design review (ui-ux-pro-max) + 5-lens enhancement review of the existing UI.

Scope: the monitor **detail page** (`resources/js/Pages/Monitors/Show.jsx`), the heatmap (`resources/js/Components/MonitorHistoryHeatmap.jsx`), status helpers (`resources/js/Utils/checkStatusSeverity.js`), a new today-bar + tooltip + tabs components, and `MonitorsController@show` (payload restructure).

Design direction (from ui-ux-pro-max): **data-dense operations dashboard** — status palette green/yellow/orange/red, indigo/purple brand accent, gray neutrals; tabular figures for numbers; WCAG AA; hover tooltips + row-hover; skeletons for async; numeric legend with labels.

---

## A. Cross-cutting design foundations (apply everywhere)

1. **Status colors (unchanged):** healthy `green-300/500/700`, warning `yellow-300`/`orange-400`, failed `red-300/500/700`, no-checks `gray-100`, unknown `gray-300`. Single source of truth in `checkStatusSeverity.js`.
2. **Brand accent:** indigo/purple (existing `purple-600` active state) for selected/active/today affordances.
3. **Numbers:** `tabular-nums` on every count, ratio, latency, and timestamp so digits don't jitter and columns align.
4. **Motion:** transitions 150ms ease-out; gate every transition/skeleton behind `motion-reduce:*` (and `matchMedia` for any JS animation).
5. **Async feedback:** all Inertia partial reloads (year nav, tab switch, pagination, filter apply) set a local pending flag → dim/disable controls + `aria-busy`; show a skeleton if the wait exceeds ~300ms. Never show stale data as live.
6. **Accessibility baseline:** contrast ≥4.5:1 (bump `gray-400` chrome text to `gray-600/700`); never color-alone (add text/icon/aria); `focus-visible` rings on all interactive elements; keyboard reachable.
7. **Headers — 2-tier system:** loud dark title (`text-base font-semibold text-gray-900`) for true sections; quiet uppercase eyebrow (`text-sm font-semibold uppercase text-gray-500`) for sub-blocks. Fix the current inversion where "Recent Checks" outweighs its parent "Monitor History".

---

## B. Target layout (Monitor History card)

Per Change 5, reorder and decouple. Flatten one card level (avoid card-in-card-in-card; use header + `border-t` dividers between blocks):

```
PageHeader:  [← Back]  Expiring Domain                         (Change 8: all left-aligned)
             https://… ↗   [live status pill + last-checked]   (enhancement E1, optional)

Monitor Snapshot   (compact strip — de-emphasized vs History)

Monitor History
 ├─ 1. GRAPHS  (NOT filter-driven)
 │     • year nav  ‹ 2026 ›   (async; bounded earliest-data-year … current year)
 │     • per chart type (uptime, domain[, cert]):
 │         – per-type headline:  "Uptime · 99.2% · 1,440 checks"   (enhancement E2)
 │         – today bar (Change 6a): thin per-check segments, most-recent-that-fit
 │         – full calendar-year heatmap (Change 4b/5): Jan→Dec, every day incl. future,
 │           month labels on X, weekday labels on Y, today highlighted (6b)
 │         – per-metric legend (Change 3)
 ├─ 2. FILTERS  (Change 1: pills + From/To + Apply on one row, matched heights)
 ├─ 3. TOTAL COUNTS  (filter-driven; lead with Reliability % — E2)
 └─ 4. RECENT CHECKS  (filter-driven; tabs + pagination — Change 2)
```

---

## C. Requested changes (1–8) — detailed spec

### Change 1 — Inline filter row
- One row: `[7d][30d][All]  From [date] To [date]  [Apply]`. Wraps gracefully < ~640px.
- **Match heights:** all controls `h-9` (36px) to match the quick-filter pills (currently the date inputs are taller). Compact padding `px-3`.
- Presets are a **segmented toggle**: `aria-pressed`, `focus-visible` ring (`ring-2 ring-purple-500`), `hover`/`active:scale-[0.97]`, `transition-colors 150ms`.
- Date inputs: branded focus ring (`focus:ring-2 focus:ring-purple-500/40`), `max=<today>`.
- Filters drive **only** totals + recent checks (see Change 5).

### Change 2 — Recent Checks: tabs + pagination
- **Tabs:** Uptime / Domain (+ Certificate only if cert becomes real — see §F). Accessible: `role=tablist/tab/tabpanel`, `aria-selected`, arrow-key nav, active underline in brand accent.
- **Pagination:** server-side via Laravel paginator, fetched with Inertia partial reload (`only:['recentChecks']`, `preserveState`, `preserveScroll`). **Default page size 25** (proposed). Style: **numbered pager** with prev/next (proposed) — or "Load more" (open decision Q1).
- Query params: `recent_type=<uptime|domain>&recent_page=<n>` (independent of graph `year`).
- Table polish: `overflow-x-auto` wrapper; row `hover:bg-gray-50`; **add right-aligned `Response (ms)` column** (`tabular-nums`; data already shipped — E5); status badges get a heroicon + Title-case label (E-a11y).

### Change 3 — Per-metric legends
- Add a per-check-type applicable-status map in `checkStatusSeverity.js`:
  - `uptime → [success, failed, unknown]`, `domain → [success, warning, failed, unknown]`, `certificate → [success, failed, unknown]`.
- Heatmap + today-bar legends filter to the applicable set (always include "No checks"). **Uptime & Certificate drop Warning.** `getCellClasses` unchanged (impossible colors never render).
- Legend a11y: text contrast `gray-600+`; each swatch carries a text label (already does).

### Change 4a — Human-readable dates (UTC)
- Shared `formatDate(iso)` → `27 Mar 2026` and `formatDateTime(iso)` → `27 Mar 2026, 15:00` via `Intl.DateTimeFormat` with **`timeZone: 'UTC'`** (must stay UTC — browser-local reintroduces the off-by-one bug). `tabular-nums`.
- Apply to heatmap header range, tooltips, and the Recent Checks `Time` column.

### Change 4b — Month X-axis labels (GitHub-style)
- Month labels row above the week columns, each aligned to the first week-column that contains that month's day-1. Weekday labels stay on Y (contrast bumped to `gray-600`).
- Compute month→column from the year grid; skip a label if a month's column would collide with the previous (< ~3 columns apart).

### Change 5 — Decouple graphs from filters; full calendar-year + year nav
- **Graphs are not filter-driven.** Default = **current calendar year** (Jan 1 – Dec 31).
- **Full-year grid:** render **every day Jan→Dec including future dates**; no-check days (past gaps and all future days) use the gray "No checks" color — never transparent/omitted. Always 365/366 cells.
- **Year nav:** prev/next arrows, bounded `[earliest-data-year … current year]` (disable beyond). Async via Inertia partial reload (`only:['graph']`, `preserveState`, `preserveScroll`). One control governs all charts.
- **Responsive fit (no scroll at width):** week-columns flex to fill the container (cell size derived from available width, capped to a sensible max). Below a min cell size, wrap the grid in `overflow-x-auto` so it scrolls only on small screens.
- **Layout order:** Graphs → Filters → Totals → Recent Checks.

### Change 6 — Per-check "today" bar + highlight today
- **6a Today bar (all chart types):** thin vertical segments, one per check, colored by status via `CHECK_STATUS_META[*].heatmapClass`. Shows today's checks; if more than fit, the **most recent X that fit** the row width (responsive). Per-segment tooltip (Change 7). Per-metric legend reused. Domain/cert (daily) will typically show one segment — accepted.
- **6b Highlight today in grid:** indigo **ring/outline** on today's cell (so the status fill still reads through) + a **"Today"** legend entry. Only when the displayed year === current year. Mark today as **provisional** in its tooltip ("Today — partial, updates on next aggregation") since daily metrics are aggregated hourly (E-state).
- Backend: today's raw checks per type (capped ~200), in the graph payload, independent of filters.

### Change 7 — Styled hover/focus tooltips
- One reusable `Tooltip` (hover **and** keyboard focus; positioned div; `role=tooltip`). Replaces the native `title` on heatmap cells and powers the thin today-bar segments (native `title` can't target few-px segments).
- **Per-day cell:** human-readable date, day status label, total/success/warning/failed counts, success ratio, avg/p95 — render "not measured" when null and **fix the `0ms` falsy bug** (use explicit null checks, not truthiness).
- **Per-check segment:** date+time, status, message, response time, failure reason (if failed).
- Reuse `CHECK_STATUS_META` labels/colors + shared UTC formatter.

### Change 8 — Back button left
- Drop `justify-between`; place **Back inline to the left** of the name/URL block; whole header left-aligned, nothing pinned right. (Alt: "← Back" breadcrumb above the title — open decision Q4.)

---

## D. Backend / architecture changes (`MonitorsController@show`)

1. **Split graph vs filter data.** Restructure the Inertia payload so partial reloads can target each independently (`only:` works at prop granularity). Proposed top-level props:
   - `graph` = `{ year, available_years, check_types[], per_type: { daily_metrics[], today_checks[], summary } }` (driven by `year`, full calendar year).
   - `filters` = the resolved range; `summary` = filter-range totals (+ all-time + by-type); `recentChecks` = paginated, tab-filtered.
2. **New query params:** `year` (default current year, UTC-resolved like the existing timezone handling) for the graph; `recent_type` + `recent_page` for the table; existing `preset/from/to` for filters.
3. **`available_years`:** min(checked_at) year … current year, to bound year nav.
4. **Recent checks → `paginate(25)`** (new pattern for the repo; the only Laravel paginator usage).
5. Surface already-computed-but-dropped data (no new queries): `summary.selected_range.success_ratio`, `summary.all_time`, `summary.by_type`, `recent_checks[].response_time_ms`, `uptime_check_failure_reason`.

---

## E. Recommended additional enhancements (beyond your 8 — opt in/out)

Curated from the 5-lens review. Grouped by tier. The recurring theme: **most of these surface data the backend already computes but the UI drops** → high value, low effort.

### Tier 1 — Strongly recommended (low effort, mostly "show what we already compute")
- **E1. Live status hero.** UP/DOWN/PENDING pill + "last checked 2m ago" in the header; show `uptime_check_failure_reason` inline when DOWN. Answers "is it up right now?" (data already on `monitor`).
- **E2. Reliability % as the lead KPI.** Promote `success_ratio` to the primary card ("Reliability 99.4% · last 30 days"), demote raw counts to a supporting row; add per-type headline on each heatmap ("Uptime · 99.2% · 1,440 checks"). Add a small "all-time 98.7%" comparison (`summary.all_time`, currently ignored).
- **E3. Response (ms) column + range latency.** Add the column to Recent Checks (data shipped, dropped) and an "Avg 312ms / P95 540ms" figure near the uptime heatmap.
- **E4. Unknown-status reconciliation.** Counts currently don't add up to Total when any check is `unknown`. Add an Unknown card or "Total N (incl. X unknown)".
- **E5. Empty-state disambiguation.** Distinguish "never monitored" from "no checks in this range"; when range is empty but all-time has data, show "No checks in this range — [View All Time]" with the first-check date.
- **E6. A11y must-haves on the heatmap.** Non-color channel (per-cell `aria-label` with status + glyph/pattern option), keyboard-focusable cells with focus ring + focus-triggered tooltip, a visually-hidden per-heatmap summary ("X healthy, Y warning, Z failed, N no-check days"), `role=grid` semantics, contrast bumps, `aria-pressed` on presets.
- **E7. Timezone label made explicit.** One clear "All times in <tz>" label on the history header / Time column (currently buried in each heatmap description).
- **E8. Micro-interaction polish.** Real cell hover lift/ring (the `transition-transform` is dead code today), row hover, button hover/active/focus states, reduced-motion policy.
- **E9. Visual hierarchy cleanups.** 2-tier headers; flatten nested cards (dividers, not card-in-card); de-emphasize disabled-check placeholders to a slim muted line; make Snapshot a compact strip vs the dominant History section.

### Tier 2 — Higher value, higher effort (opt-in)
- **E10. SSL/cert + domain expiry countdown badges** in Snapshot ("SSL valid 42 days", red < 7). (Cert depends on §F.)
- **E11. Outage timeline.** "Last outage: 3 days ago (12m)", "Longest outage", "Incidents: N" — needs server-side derivation from `checkLogs` status transitions. Highest-value net-new for incident review.
- **E12. Uptime/downtime streak.** "Operational for 14 days" / "Down 8m" next to the live-status pill.
- **E13. Custom-range validation feedback.** `max=today`, `from<=to`, and an inline "Adjusted to <from>–<to>" note when the server clamps/swaps (today it silently changes the inputs).
- **E14. CSV export** of the range's checks (deferred unless wanted).

---

## F. Important finding — certificate check is dead UI

`certificate_check_enabled` is **never written** by `store`/`update`, and the create/edit forms expose only Uptime + Domain toggles. So for real user monitors the certificate heatmap/tab/today-bar always render "not enabled."
**Recommendation / decision (Q5):** either (a) wire a Certificate toggle into the form + controller (small, makes cert real and unlocks E10), or (b) drop certificate from the tabs/today-bar/graphs for now and only render it if a monitor actually has it enabled. Until decided, cert-specific UI (Change 2 tab, Change 6 bar, Change 3 legend) stays dormant.

---

## G. Open decisions to confirm

- **Q1.** Recent Checks pagination: numbered pager (proposed) vs "Load more"/infinite. Page size 25 ok?
- **Q2.** Recent Checks tabs: Uptime + Domain only, or include Certificate (see §F)?
- **Q3.** Change 4a date format: `27 Mar 2026` (proposed) vs `Mar 27, 2026` vs other?
- **Q4.** Change 8: Back inline-left of the title (proposed) vs "← Back" breadcrumb above it?
- **Q5.** Certificate (§F): wire it up, or defer/hide cert UI?
- **Q6.** Which Tier-1 / Tier-2 enhancements to include in this pass? (Tier-1 are cheap, high-value; recommend taking all of Tier-1.)

---

## H. Proposed implementation phases (after approval)

Branch off `main` first. Each phase verified (build + targeted tests where backend logic changes).

1. **Foundations:** `checkStatusSeverity.js` per-type status map; shared UTC date formatters; reusable `Tooltip`; status→color/label helpers. (Changes 3, 4a, 7 groundwork)
2. **Backend payload split:** `graph` vs `filters/summary/recentChecks`; `year` + `available_years`; recent-checks `paginate` + `recent_type`; surface dropped fields. Tests for the new payload shape + pagination + year scoping. (Change 5, 2 backend; E2–E5 data)
3. **Graphs section:** full-year responsive heatmap, month axis, today highlight, today bar, per-metric legend, tooltips, year nav. (Changes 4b, 5, 6, 3, 7)
4. **Filters + Totals:** inline filter row, decoupled from graph; reliability-led KPI cards; unknown reconciliation; empty states. (Changes 1; E2, E4, E5)
5. **Recent Checks:** tabs + pagination + response column + row hover + badge icons (async partial reloads). (Change 2; E3)
6. **Header + polish:** Back button left, live-status hero, snapshot/hierarchy cleanup, a11y pass, micro-interactions, reduced-motion, timezone label. (Change 8; E1, E6–E9)
7. **(Optional Tier 2):** cert wiring/decision, outage timeline, streak, custom-range validation.

Frontend verified via `npm run build` (no JS test runner); backend via `php artisan test` against `monitor_test`.
