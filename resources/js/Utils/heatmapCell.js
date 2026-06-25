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

// Graded (uptime): three shades per status, by the day's success ratio.
function gradedClass(point) {
    const status = normalizeCheckStatus(point.worst_status);
    const ratio = Number(point.success_ratio || 0);

    if (status === CHECK_STATUS.FAILED) {
        if (ratio < 30) return "bg-red-700 border-red-700";
        if (ratio < 70) return "bg-red-500 border-red-500";
        return "bg-red-300 border-red-300";
    }
    if (status === CHECK_STATUS.WARNING) {
        if (ratio < 80) return "bg-orange-400 border-orange-400";
        return "bg-yellow-300 border-yellow-300";
    }
    if (status === CHECK_STATUS.SUCCESS) {
        if (ratio >= 99) return "bg-green-700 border-green-700";
        if (ratio >= 95) return "bg-green-500 border-green-500";
        return "bg-green-300 border-green-300";
    }
    return "bg-gray-300 border-gray-300";
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
        return gradedClass(point);
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

    lines.push(`Success ratio: ${point.success_ratio}%`);

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
