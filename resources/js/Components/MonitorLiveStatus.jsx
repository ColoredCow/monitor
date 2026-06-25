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
