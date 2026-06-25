import React, { useRef } from "react";
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
    QuestionMarkCircleIcon,
} from "@heroicons/react/24/outline";
import Badge from "@/Components/Badge";
import { formatDateTimeUTC } from "@/Utils/formatDate";
import {
    normalizeCheckStatus,
    getCheckStatusBadgeColor,
    getCheckStatusMeta,
} from "@/Utils/checkStatusSeverity";

const CHECK_TYPE_LABELS = {
    uptime: "Uptime",
    domain: "Domain",
};

const STATUS_ICONS = {
    success: CheckCircleIcon,
    warning: ExclamationTriangleIcon,
    failed: XCircleIcon,
    unknown: QuestionMarkCircleIcon,
};

function StatusBadge({ status }) {
    const normalized = normalizeCheckStatus(status);
    const Icon = STATUS_ICONS[normalized] || QuestionMarkCircleIcon;

    return (
        <Badge
            text={getCheckStatusMeta(normalized).label}
            color={getCheckStatusBadgeColor(normalized)}
            icon={<Icon className="h-3.5 w-3.5" aria-hidden="true" />}
        />
    );
}

function buildPageList(currentPage, lastPage) {
    if (lastPage <= 7) {
        return Array.from({ length: lastPage }, (_, index) => index + 1);
    }

    const pages = new Set([1, lastPage, currentPage]);
    if (currentPage - 1 >= 1) pages.add(currentPage - 1);
    if (currentPage + 1 <= lastPage) pages.add(currentPage + 1);

    const sorted = Array.from(pages).sort((a, b) => a - b);
    const withGaps = [];
    let previous = 0;
    for (const page of sorted) {
        if (previous && page - previous > 1) {
            withGaps.push(`gap-${previous}`);
        }
        withGaps.push(page);
        previous = page;
    }

    return withGaps;
}

export default function RecentChecksPanel({
    recentChecks,
    checkTypes,
    pending,
    onTabChange,
    onPageChange,
}) {
    const tabRefs = useRef({});
    const tabs = (checkTypes || []).filter(
        (entry) => entry.type === "uptime" || entry.type === "domain"
    );
    const activeType = recentChecks?.type || tabs[0]?.type || "uptime";
    const rows = recentChecks?.data || [];
    const pagination = recentChecks?.pagination || {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
    };

    const handleTabKeyDown = (event) => {
        if (event.key !== "ArrowRight" && event.key !== "ArrowLeft") {
            return;
        }
        event.preventDefault();
        const index = tabs.findIndex((entry) => entry.type === activeType);
        const delta = event.key === "ArrowRight" ? 1 : -1;
        const nextIndex = (index + delta + tabs.length) % tabs.length;
        const nextType = tabs[nextIndex]?.type;
        if (nextType) {
            tabRefs.current[nextType]?.focus();
            onTabChange(nextType);
        }
    };

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 className="text-base font-semibold text-gray-900">
                Recent Checks
            </h3>

            <div
                role="tablist"
                aria-label="Recent checks by check type"
                className="mt-4 flex items-center gap-6 border-b border-gray-200"
                onKeyDown={handleTabKeyDown}
            >
                {tabs.map(({ type }) => {
                    const isActive = type === activeType;

                    return (
                        <button
                            key={type}
                            type="button"
                            role="tab"
                            id={`recent-checks-tab-${type}`}
                            aria-selected={isActive}
                            aria-controls="recent-checks-panel"
                            tabIndex={isActive ? 0 : -1}
                            ref={(node) => {
                                tabRefs.current[type] = node;
                            }}
                            disabled={pending}
                            onClick={() => {
                                if (!isActive) {
                                    onTabChange(type);
                                }
                            }}
                            className={[
                                "-mb-px border-b-2 px-1 pb-2 text-sm font-medium transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:rounded-sm disabled:cursor-not-allowed disabled:opacity-60",
                                isActive
                                    ? "border-purple-600 text-purple-700"
                                    : "border-transparent text-gray-600 hover:text-gray-700",
                            ].join(" ")}
                        >
                            {CHECK_TYPE_LABELS[type] || type}
                        </button>
                    );
                })}
            </div>

            <div
                role="tabpanel"
                id="recent-checks-panel"
                aria-labelledby={`recent-checks-tab-${activeType}`}
                aria-busy={pending}
                className="mt-4 overflow-x-auto"
            >
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="text-left text-gray-600 border-b border-gray-200">
                            <th className="py-2 pr-4 font-medium">Time</th>
                            <th className="py-2 pr-4 font-medium">Type</th>
                            <th className="py-2 pr-4 font-medium">Status</th>
                            <th className="py-2 pr-4 font-medium">Message</th>
                            <th className="py-2 pl-4 font-medium text-right">
                                Response (ms)
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td
                                    className="py-4 text-gray-600"
                                    colSpan={5}
                                >
                                    No checks recorded for this range.
                                </td>
                            </tr>
                        ) : (
                            rows.map((check) => (
                                <tr
                                    key={check.id}
                                    className="border-b border-gray-100 last:border-b-0 hover:bg-gray-50 transition-colors duration-150 motion-reduce:transition-none"
                                >
                                    <td className="py-2 pr-4 text-gray-700 whitespace-nowrap tabular-nums">
                                        {formatDateTimeUTC(check.checked_at)}
                                    </td>
                                    <td className="py-2 pr-4 text-gray-700">
                                        {CHECK_TYPE_LABELS[check.check_type] ||
                                            check.check_type}
                                    </td>
                                    <td className="py-2 pr-4">
                                        <StatusBadge status={check.status} />
                                    </td>
                                    <td className="py-2 pr-4 text-gray-700">
                                        {check.message ||
                                            check.failure_reason ||
                                            "No details"}
                                    </td>
                                    <td className="py-2 pl-4 text-right text-gray-700 tabular-nums whitespace-nowrap">
                                        {check.response_time_ms ?? "—"}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {pagination.last_page > 1 ? (
                <nav
                    aria-label="Recent checks pagination"
                    className="mt-4 flex items-center justify-center gap-1"
                >
                    <button
                        type="button"
                        disabled={pending || pagination.current_page <= 1}
                        onClick={() =>
                            onPageChange(pagination.current_page - 1)
                        }
                        className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Prev
                    </button>

                    {buildPageList(
                        pagination.current_page,
                        pagination.last_page
                    ).map((page) =>
                        typeof page === "string" ? (
                            <span
                                key={page}
                                className="px-2 py-1.5 text-sm text-gray-400"
                                aria-hidden="true"
                            >
                                …
                            </span>
                        ) : (
                            <button
                                key={page}
                                type="button"
                                aria-current={
                                    page === pagination.current_page
                                        ? "page"
                                        : undefined
                                }
                                disabled={pending}
                                onClick={() => onPageChange(page)}
                                className={[
                                    "min-w-[2.25rem] rounded-lg border px-3 py-1.5 text-sm font-medium tabular-nums transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 disabled:cursor-not-allowed disabled:opacity-50",
                                    page === pagination.current_page
                                        ? "border-purple-600 bg-purple-600 text-white"
                                        : "border-gray-300 text-gray-700 hover:bg-gray-50",
                                ].join(" ")}
                            >
                                {page}
                            </button>
                        )
                    )}

                    <button
                        type="button"
                        disabled={
                            pending ||
                            pagination.current_page >= pagination.last_page
                        }
                        onClick={() =>
                            onPageChange(pagination.current_page + 1)
                        }
                        className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Next
                    </button>
                </nav>
            ) : null}
        </div>
    );
}
