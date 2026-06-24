import React, { useMemo } from "react";
import { CHECK_STATUS, normalizeCheckStatus } from "@/Utils/checkStatusSeverity";

const WEEKDAY_LABELS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

// Mirrors the cell colors produced by getCellClasses(): healthy and failed cells
// are graded by success ratio (lighter = lower volume/ratio, darker = higher), and
// warning cells use yellow for high ratios and orange for low ones.
const LEGEND_ITEMS = [
    { label: "No checks", swatches: ["bg-gray-100 border-gray-200"] },
    {
        label: "Healthy",
        swatches: [
            "bg-green-300 border-green-300",
            "bg-green-500 border-green-500",
            "bg-green-700 border-green-700",
        ],
    },
    {
        label: "Warning",
        swatches: [
            "bg-yellow-300 border-yellow-300",
            "bg-orange-400 border-orange-400",
        ],
    },
    {
        label: "Failed",
        swatches: [
            "bg-red-300 border-red-300",
            "bg-red-500 border-red-500",
            "bg-red-700 border-red-700",
        ],
    },
    { label: "Unknown", swatches: ["bg-gray-300 border-gray-300"] },
];

function parseIsoDateUTC(isoDate) {
    const [year, month, day] = isoDate.split("-").map(Number);
    return new Date(Date.UTC(year, month - 1, day));
}

function formatDateUTC(date) {
    const year = date.getUTCFullYear();
    const month = String(date.getUTCMonth() + 1).padStart(2, "0");
    const day = String(date.getUTCDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
}

function addDaysUTC(date, days) {
    return new Date(date.getTime() + days * 24 * 60 * 60 * 1000);
}

function startOfWeekUTC(date) {
    return addDaysUTC(date, -date.getUTCDay());
}

function endOfWeekUTC(date) {
    return addDaysUTC(date, 6 - date.getUTCDay());
}

function buildCalendarWeeks(fromDate, toDate) {
    const rangeStart = startOfWeekUTC(parseIsoDateUTC(fromDate));
    const rangeEnd = endOfWeekUTC(parseIsoDateUTC(toDate));

    const allDays = [];
    for (
        let cursor = rangeStart;
        cursor.getTime() <= rangeEnd.getTime();
        cursor = addDaysUTC(cursor, 1)
    ) {
        allDays.push(new Date(cursor));
    }

    const weeks = [];
    for (let index = 0; index < allDays.length; index += 7) {
        weeks.push(allDays.slice(index, index + 7));
    }

    return weeks;
}

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

function buildTooltip(point, isoDate) {
    if (!point || point.total_checks === 0) {
        return `${isoDate}\nNo checks recorded`;
    }

    return [
        isoDate,
        `Total checks: ${point.total_checks}`,
        `Success: ${point.successful_checks}`,
        `Warning: ${point.warning_checks}`,
        `Failed: ${point.failed_checks}`,
        `Success ratio: ${point.success_ratio}%`,
        point.avg_response_time_ms
            ? `Avg response: ${point.avg_response_time_ms}ms`
            : null,
        point.p95_response_time_ms
            ? `P95 response: ${point.p95_response_time_ms}ms`
            : null,
    ]
        .filter(Boolean)
        .join("\n");
}

export default function MonitorHistoryHeatmap({
    title,
    description,
    fromDate,
    toDate,
    points = [],
}) {
    const pointMap = useMemo(() => {
        return new Map(points.map((point) => [point.date, point]));
    }, [points]);

    const weeks = useMemo(() => {
        return buildCalendarWeeks(fromDate, toDate);
    }, [fromDate, toDate]);

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div className="mb-4">
                <h3 className="text-base font-semibold text-gray-900">{title}</h3>
                {description ? (
                    <p className="mt-1 text-sm text-gray-500">{description}</p>
                ) : null}
            </div>

            <div className="overflow-x-auto">
                <div className="inline-flex gap-2 items-start min-w-max">
                    <div className="grid grid-rows-7 gap-1 pr-1">
                        {WEEKDAY_LABELS.map((label) => (
                            <span
                                key={label}
                                className="h-3.5 text-[10px] text-gray-400 leading-3"
                            >
                                {label}
                            </span>
                        ))}
                    </div>

                    <div className="flex gap-1">
                        {weeks.map((week, weekIndex) => (
                            <div key={weekIndex} className="grid grid-rows-7 gap-1">
                                {week.map((day) => {
                                    const isoDate = formatDateUTC(day);
                                    const point = pointMap.get(isoDate);
                                    const inRange =
                                        isoDate >= fromDate && isoDate <= toDate;

                                    return (
                                        <div
                                            key={isoDate}
                                            className={[
                                                "h-3.5 w-3.5 rounded-sm border transition-transform",
                                                inRange
                                                    ? getCellClasses(point)
                                                    : "bg-transparent border-transparent",
                                            ].join(" ")}
                                            title={
                                                inRange
                                                    ? buildTooltip(point, isoDate)
                                                    : ""
                                            }
                                        />
                                    );
                                })}
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <div className="mt-4 flex items-center flex-wrap gap-x-4 gap-y-2 text-xs text-gray-500">
                {LEGEND_ITEMS.map((item) => (
                    <span key={item.label} className="flex items-center gap-1.5">
                        <span className="flex gap-0.5">
                            {item.swatches.map((swatch) => (
                                <span
                                    key={swatch}
                                    className={`h-3.5 w-3.5 rounded-sm border ${swatch}`}
                                />
                            ))}
                        </span>
                        {item.label}
                    </span>
                ))}
            </div>
        </div>
    );
}
