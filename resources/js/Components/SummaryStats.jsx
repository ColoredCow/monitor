import React from "react";
import { formatDateTimeUTC } from "@/Utils/formatDate";

function reliabilityColor(ratio) {
    if (ratio >= 99) return "text-green-700";
    if (ratio >= 95) return "text-green-600";
    if (ratio >= 80) return "text-yellow-600";
    return "text-red-600";
}

function formatRatio(ratio) {
    return `${Number(ratio || 0).toFixed(1)}%`;
}

// Includes Unknown so the four counts reconcile to total_checks (E4).
const COUNT_ITEMS = [
    { key: "success", label: "Success", className: "text-green-700" },
    { key: "warning", label: "Warning", className: "text-yellow-700" },
    { key: "failed", label: "Failed", className: "text-red-700" },
    { key: "unknown", label: "Unknown", className: "text-gray-600" },
];

export default function SummaryStats({ summary, onViewAllTime }) {
    const selected = summary?.selected_range || null;
    const allTime = summary?.all_time || null;
    const selectedTotal = selected?.total_checks || 0;
    const allTimeTotal = allTime?.total_checks || 0;

    // E5: first-run empty state — nothing has ever been checked.
    if (allTimeTotal === 0) {
        return (
            <div className="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-6 text-center">
                <p className="text-sm font-medium text-gray-700">
                    No checks have been recorded yet
                </p>
                <p className="mt-1 text-sm text-gray-500">
                    {summary?.first_checked_at
                        ? `First check expected around ${formatDateTimeUTC(summary.first_checked_at)}.`
                        : "Reliability stats will appear once this monitor runs its first check."}
                </p>
            </div>
        );
    }

    // E5: data exists all-time but the selected range is empty — disambiguate.
    if (selectedTotal === 0) {
        return (
            <div className="rounded-xl border border-gray-200 bg-white p-6">
                <p className="text-sm font-medium text-gray-700">
                    No checks in this range
                </p>
                <p className="mt-1 text-sm text-gray-500">
                    This monitor has{" "}
                    <span className="tabular-nums font-semibold text-gray-700">
                        {allTimeTotal}
                    </span>{" "}
                    checks all-time at{" "}
                    <span className="tabular-nums font-semibold text-gray-700">
                        {formatRatio(allTime?.success_ratio)}
                    </span>{" "}
                    reliability.{" "}
                    <button
                        type="button"
                        onClick={() => onViewAllTime?.()}
                        className="font-semibold text-purple-600 hover:text-purple-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:ring-offset-1 rounded"
                    >
                        View All Time
                    </button>
                </p>
            </div>
        );
    }

    const selectedRatio = Number(selected?.success_ratio || 0);
    const totals = selected?.status_totals || {};

    return (
        <div className="flex flex-col gap-4 lg:flex-row lg:items-stretch">
            <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:w-64">
                <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
                    Reliability
                </p>
                <p
                    className={`mt-1 text-4xl font-bold tabular-nums ${reliabilityColor(
                        selectedRatio
                    )}`}
                >
                    {formatRatio(selectedRatio)}
                </p>
                <p className="mt-1 text-xs text-gray-500">
                    <span className="tabular-nums">{selectedTotal}</span> checks in
                    range · all-time{" "}
                    <span className="tabular-nums font-medium text-gray-600">
                        {formatRatio(allTime?.success_ratio)}
                    </span>
                </p>
            </div>

            <div className="grid flex-1 grid-cols-2 gap-3 sm:grid-cols-4">
                {COUNT_ITEMS.map((item) => (
                    <div
                        key={item.key}
                        className="rounded-xl border border-gray-200 bg-white p-4"
                    >
                        <p className="text-xs uppercase tracking-wide text-gray-500">
                            {item.label}
                        </p>
                        <p
                            className={`mt-2 text-2xl font-bold tabular-nums ${item.className}`}
                        >
                            {totals[item.key] || 0}
                        </p>
                    </div>
                ))}
            </div>
        </div>
    );
}
