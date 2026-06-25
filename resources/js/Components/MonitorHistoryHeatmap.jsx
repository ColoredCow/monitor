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
