import {
    CHECK_STATUS,
    normalizeCheckStatus,
    getCheckStatusMeta,
    statusesForCheckType,
} from "@/Utils/checkStatusSeverity";

const NO_DATA_CLASS = "bg-gray-100 border-gray-200";

// Only uptime is high-frequency (many checks/day), so only uptime has a
// meaningful per-day success ratio and per-day latency. Domain & certificate
// run once a day → a single solid color per status, no gradient, no latency.
export function isGradedCheckType(checkType) {
    return checkType === "uptime";
}

// Uptime: one 4-band scale by the day's success percentage (highest band first).
// Shared by the cells AND the legend so they always agree.
export const UPTIME_BANDS = [
    { min: 100, label: "100%", class: "bg-green-600 border-green-600" },
    { min: 95, label: "95%+", class: "bg-green-400 border-green-400" },
    { min: 90, label: "90%+", class: "bg-amber-400 border-amber-400" },
    { min: 0, label: "<90%", class: "bg-red-500 border-red-500" },
];

// A day with only "unknown" checks (e.g. never-checked) has a 0% ratio but no
// real failures — keep it gray rather than letting it fall into the red band.
const UNKNOWN_DAY_CLASS = "bg-gray-300 border-gray-300";

function uptimeBandClass(point) {
    if (normalizeCheckStatus(point.worst_status) === CHECK_STATUS.UNKNOWN) {
        return UNKNOWN_DAY_CLASS;
    }
    const ratio = Number(point.success_ratio || 0);
    const band =
        UPTIME_BANDS.find((entry) => ratio >= entry.min) ||
        UPTIME_BANDS[UPTIME_BANDS.length - 1];

    return band.class;
}

// Single solid color per status (domain / certificate). Shared by cells + legend.
export const SINGLE_STATUS_CLASS = {
    [CHECK_STATUS.SUCCESS]: "bg-green-600 border-green-600",
    [CHECK_STATUS.WARNING]: "bg-orange-400 border-orange-400",
    [CHECK_STATUS.FAILED]: "bg-red-600 border-red-600",
    [CHECK_STATUS.UNKNOWN]: "bg-gray-400 border-gray-400",
};

export function cellColorClass(point, checkType) {
    if (!point || point.total_checks === 0) {
        return NO_DATA_CLASS;
    }
    if (isGradedCheckType(checkType)) {
        return uptimeBandClass(point);
    }
    return (
        SINGLE_STATUS_CLASS[normalizeCheckStatus(point.worst_status)] ||
        SINGLE_STATUS_CLASS[CHECK_STATUS.UNKNOWN]
    );
}

// Status -> [tooltip row label, daily_metrics count field]. Unknown has no
// dedicated count field in daily_metrics, so it gets no count row.
const COUNT_ROW = {
    [CHECK_STATUS.SUCCESS]: ["Success", "successful_checks"],
    [CHECK_STATUS.WARNING]: ["Warning", "warning_checks"],
    [CHECK_STATUS.FAILED]: ["Failed", "failed_checks"],
};

// Per-metric tooltip body lines (no date / provisional note — the component adds those).
export function cellMetricLines(point, checkType) {
    if (!point || point.total_checks === 0) {
        return ["No checks"];
    }

    const lines = [
        `Status: ${getCheckStatusMeta(point.worst_status).label}`,
        `Total checks: ${point.total_checks}`,
    ];

    // Only the status-count rows this check type can actually produce
    // (uptime/certificate never warn, so no "Warning: 0" noise).
    statusesForCheckType(checkType).forEach((status) => {
        const row = COUNT_ROW[status];
        if (row) {
            lines.push(`${row[0]}: ${point[row[1]] ?? 0}`);
        }
    });

    // For uptime the success ratio IS the day's uptime %; label it as such.
    const ratioLabel = isGradedCheckType(checkType) ? "Uptime" : "Success ratio";
    lines.push(`${ratioLabel}: ${point.success_ratio}%`);

    // Latency only for uptime, and only when actually measured.
    if (isGradedCheckType(checkType)) {
        const { avg_response_time_ms: avg, p95_response_time_ms: p95 } = point;
        if (avg !== null && avg !== undefined) {
            lines.push(`Avg response: ${avg}ms`);
        }
        if (p95 !== null && p95 !== undefined) {
            lines.push(`P95 response: ${p95}ms`);
        }
    }

    return lines;
}
