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
    statusesForCheckType,
} from "@/Utils/checkStatusSeverity";
import {
    cellColorClass,
    cellMetricLines,
    isGradedCheckType,
    SINGLE_STATUS_CLASS,
    UPTIME_BANDS,
} from "@/Utils/heatmapCell";
import Tooltip from "@/Components/Tooltip";

const WEEKDAY_LABELS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
const CELL_GAP = 3;
const CELL_MIN = 10;
const CELL_MAX = 16;
// Fixed width of the weekday-label (Y-axis) column. The month-label row offsets
// by this, and cell sizing reserves it, so labels, grid and months stay aligned.
const WEEKDAY_LABEL_WIDTH = 28;
// Today is a solid indigo fill (overrides the day's status hue — today is
// provisional anyway), in both the grid and the legend.
const TODAY_CLASS = "bg-indigo-500 border-indigo-500";

// Uptime's legend is the 4-band percentage scale (UPTIME_BANDS). Domain &
// certificate use a single solid swatch per status (SINGLE_STATUS_CLASS) with
// these labels, since they are once-daily checks with no per-day ratio.
const STATUS_LABEL = {
    [CHECK_STATUS.SUCCESS]: "Healthy",
    [CHECK_STATUS.WARNING]: "Warning",
    [CHECK_STATUS.FAILED]: "Failed",
    [CHECK_STATUS.UNKNOWN]: "Unknown",
};

function buildCellTooltip(point, iso, isToday, checkType) {
    const note = isToday ? "\nToday — partial, updates on next aggregation" : "";

    return (
        [formatDateUTC(iso), ...cellMetricLines(point, checkType)].join("\n") + note
    );
}

// Reduced-motion policy: this component animates only via CSS transitions, each of
// which carries `motion-reduce:transition-none motion-reduce:transform-none`. There is
// no JS-driven animation here, so no `matchMedia('(prefers-reduced-motion: reduce)')`
// gate is required — the CSS variants fully satisfy the prefers-reduced-motion contract.
export default function MonitorHistoryHeatmap({
    checkType,
    title,
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
                    reserved: WEEKDAY_LABEL_WIDTH + CELL_GAP,
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
    const graded = isGradedCheckType(checkType);
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
    const labelTrackStyle = { height: cellSize, width: WEEKDAY_LABEL_WIDTH };

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            {/* No visible heading here: the per-type headline above already names
                the metric, and the year/timezone are shown in the year nav and the
                global "All times in …" label — see Show.jsx. The `title` is still
                used for the screen-reader summary and the grid's aria-label. */}
            <p className="sr-only">{srSummary}</p>

            {/* py padding gives the bottom/top row hover (scale + ring) room so it
                isn't clipped — overflow-x-auto forces overflow-y to clip too. */}
            <div ref={containerRef} className="overflow-x-auto py-1.5">
                <div className="inline-flex flex-col gap-1 min-w-max">
                    {/* Month label row, aligned to the first week-column of each month. */}
                    <div
                        className="flex"
                        style={{
                            gap: CELL_GAP,
                            marginLeft: WEEKDAY_LABEL_WIDTH + CELL_GAP,
                        }}
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
                                                content={buildCellTooltip(
                                                    point,
                                                    day.iso,
                                                    isToday,
                                                    checkType
                                                )}
                                            >
                                                <div
                                                    role="gridcell"
                                                    tabIndex={0}
                                                    aria-label={buildCellTooltip(
                                                        point,
                                                        day.iso,
                                                        isToday,
                                                        checkType
                                                    ).replace(/\n/g, ", ")}
                                                    className={[
                                                        "rounded-sm border transition-transform duration-150 ease-out",
                                                        "hover:scale-110 hover:ring-1 hover:ring-gray-400",
                                                        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500",
                                                        "motion-reduce:transition-none motion-reduce:transform-none",
                                                        isToday
                                                            ? TODAY_CLASS
                                                            : cellColorClass(point, checkType),
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
                {graded
                    ? UPTIME_BANDS.map((band) => (
                          <span
                              key={band.label}
                              className="flex items-center gap-1.5"
                          >
                              <span
                                  className={`h-3.5 w-3.5 rounded-sm border ${band.class}`}
                              />
                              {band.label}
                          </span>
                      ))
                    : legendStatuses
                          .filter((status) => STATUS_LABEL[status])
                          .map((status) => (
                              <span
                                  key={status}
                                  className="flex items-center gap-1.5"
                              >
                                  <span
                                      className={`h-3.5 w-3.5 rounded-sm border ${SINGLE_STATUS_CLASS[status]}`}
                                  />
                                  {STATUS_LABEL[status]}
                              </span>
                          ))}
                {isCurrentYear ? (
                    <span className="flex items-center gap-1.5">
                        <span className={`h-3.5 w-3.5 rounded-sm border ${TODAY_CLASS}`} />
                        Today
                    </span>
                ) : null}
            </div>
        </div>
    );
}
