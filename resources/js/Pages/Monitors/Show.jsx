import React, { useEffect, useMemo, useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, Link, router, usePage } from "@inertiajs/react";
import {
    ArrowTopRightOnSquareIcon,
    ArrowLeftIcon,
} from "@heroicons/react/24/outline";
import MonitorUptimeIcon from "@/Components/MonitorUptimeIcon";
import MonitorDomainIcon from "@/Components/MonitorDomainIcon";
import MonitorCheckIntervalIcon from "@/Components/MonitorCheckIntervalIcon";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Input from "@/Components/Input";
import Badge from "@/Components/Badge";
import MonitorHistoryHeatmap from "@/Components/MonitorHistoryHeatmap";
import {
    getCheckStatusBadgeColor,
    normalizeCheckStatus,
} from "@/Utils/checkStatusSeverity";

const CHECK_TYPE_LABELS = {
    uptime: "Uptime",
    domain: "Domain",
    certificate: "Certificate",
};

function formatCheckTypeLabel(checkType) {
    return CHECK_TYPE_LABELS[checkType] || checkType;
}

export default function Show(props) {
    const { monitor, features, history } = usePage().props;
    const isHistoryEnabled = Boolean(features?.monitorHistory);
    const selectedRange = history?.range || null;

    const [customRange, setCustomRange] = useState({
        from: selectedRange?.from || "",
        to: selectedRange?.to || "",
    });

    // Keep the custom-range inputs in sync with the range the server actually applied
    // (it may clamp or swap the supplied dates) so the controls never misrepresent the view.
    useEffect(() => {
        setCustomRange({
            from: selectedRange?.from || "",
            to: selectedRange?.to || "",
        });
    }, [selectedRange?.from, selectedRange?.to]);

    const applyRange = (params) => {
        // Timezone is resolved server-side (it must match how metrics were aggregated),
        // so we intentionally do not send the browser timezone here.
        router.get(route("monitors.show", monitor.id), params, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const submitCustomRange = (event) => {
        event.preventDefault();
        applyRange({
            preset: "custom",
            from: customRange.from,
            to: customRange.to,
        });
    };

    const checkTypes = useMemo(() => {
        if (history?.check_types?.length) {
            return history.check_types;
        }

        // Fallback: derive enabled flags from the monitor when no payload is present.
        return [
            { type: "uptime", enabled: Boolean(monitor.uptime_check_enabled) },
            { type: "domain", enabled: Boolean(monitor.domain_check_enabled) },
            {
                type: "certificate",
                enabled: Boolean(monitor.certificate_check_enabled),
            },
        ];
    }, [history, monitor]);

    const statusTotals = history?.summary?.selected_range?.status_totals || {};
    const totalChecks = history?.summary?.selected_range?.total_checks || 0;

    return (
        <Authenticated auth={props.auth} errors={props.errors}>
            <Head title={monitor.name || "Monitor Details"} />

            <PageHeader>
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                            {monitor.name}
                        </h1>
                        <div className="mt-1 flex items-center gap-1 text-sm text-gray-500">
                            <span className="truncate max-w-[35rem]">{monitor.raw_url}</span>
                            <a
                                href={monitor.raw_url}
                                target="_blank"
                                rel="noreferrer"
                                className="text-gray-400 hover:text-gray-600"
                                title="Open monitor URL"
                            >
                                <ArrowTopRightOnSquareIcon className="h-4 w-4" />
                            </a>
                        </div>
                    </div>
                    <Link
                        href={route("monitors.index")}
                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                    >
                        <ArrowLeftIcon className="h-4 w-4" />
                        Back
                    </Link>
                </div>
            </PageHeader>

            <div className="max-w-7xl mx-auto py-8 px-6 lg:px-8 space-y-6">
                <div className="bg-white rounded-xl p-6 border border-gray-200 shadow-sm">
                    <h2 className="text-sm font-semibold tracking-wide text-gray-500 uppercase mb-4">
                        Monitor Snapshot
                    </h2>
                    <div className="flex items-center gap-2.5 flex-wrap">
                        <MonitorUptimeIcon monitor={monitor} />
                        <MonitorCheckIntervalIcon monitor={monitor} />
                        <MonitorDomainIcon monitor={monitor} />
                    </div>
                </div>

                <div className="bg-white rounded-xl p-6 border border-gray-200 shadow-sm">
                    <h2 className="text-sm font-semibold tracking-wide text-gray-500 uppercase mb-2">
                        Monitor History
                    </h2>

                    {!isHistoryEnabled ? (
                        <p className="text-sm text-gray-600">
                            History view is disabled. Set{" "}
                            <code>MONITOR_HISTORY_ENABLED=true</code> to enable rollout when
                            backend history ingestion is ready.
                        </p>
                    ) : !history ? (
                        <p className="text-sm text-gray-600">
                            History is enabled, but no history payload is available for
                            this monitor yet.
                        </p>
                    ) : (
                        <div className="space-y-6">
                            <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <button
                                        className={`px-3 py-1.5 rounded-lg border text-xs font-semibold ${
                                            selectedRange?.preset === "7d"
                                                ? "bg-purple-600 text-white border-purple-600"
                                                : "bg-white text-gray-700 border-gray-300"
                                        }`}
                                        onClick={() => applyRange({ preset: "7d" })}
                                        type="button"
                                    >
                                        Last 7 Days
                                    </button>
                                    <button
                                        className={`px-3 py-1.5 rounded-lg border text-xs font-semibold ${
                                            selectedRange?.preset === "30d"
                                                ? "bg-purple-600 text-white border-purple-600"
                                                : "bg-white text-gray-700 border-gray-300"
                                        }`}
                                        onClick={() => applyRange({ preset: "30d" })}
                                        type="button"
                                    >
                                        Last 30 Days
                                    </button>
                                    <button
                                        className={`px-3 py-1.5 rounded-lg border text-xs font-semibold ${
                                            selectedRange?.preset === "all"
                                                ? "bg-purple-600 text-white border-purple-600"
                                                : "bg-white text-gray-700 border-gray-300"
                                        }`}
                                        onClick={() => applyRange({ preset: "all" })}
                                        type="button"
                                    >
                                        All Time
                                    </button>
                                </div>

                                <form
                                    onSubmit={submitCustomRange}
                                    className="mt-4 flex flex-wrap items-end gap-3"
                                >
                                    <div>
                                        <label className="block text-xs text-gray-500 mb-1">
                                            From
                                        </label>
                                        <Input
                                            type="date"
                                            name="from"
                                            value={customRange.from}
                                            handleChange={(event) =>
                                                setCustomRange((previous) => ({
                                                    ...previous,
                                                    from: event.target.value,
                                                }))
                                            }
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs text-gray-500 mb-1">
                                            To
                                        </label>
                                        <Input
                                            type="date"
                                            name="to"
                                            value={customRange.to}
                                            handleChange={(event) =>
                                                setCustomRange((previous) => ({
                                                    ...previous,
                                                    to: event.target.value,
                                                }))
                                            }
                                        />
                                    </div>
                                    <Button type="submit" className="px-4 py-2">
                                        Apply
                                    </Button>
                                </form>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div className="rounded-xl border border-gray-200 bg-white p-4">
                                    <p className="text-xs uppercase tracking-wide text-gray-500">
                                        Total Checks
                                    </p>
                                    <p className="mt-2 text-2xl font-bold text-gray-900">
                                        {totalChecks}
                                    </p>
                                </div>
                                <div className="rounded-xl border border-gray-200 bg-white p-4">
                                    <p className="text-xs uppercase tracking-wide text-gray-500">
                                        Success
                                    </p>
                                    <p className="mt-2 text-2xl font-bold text-green-700">
                                        {statusTotals.success || 0}
                                    </p>
                                </div>
                                <div className="rounded-xl border border-gray-200 bg-white p-4">
                                    <p className="text-xs uppercase tracking-wide text-gray-500">
                                        Warning
                                    </p>
                                    <p className="mt-2 text-2xl font-bold text-yellow-700">
                                        {statusTotals.warning || 0}
                                    </p>
                                </div>
                                <div className="rounded-xl border border-gray-200 bg-white p-4">
                                    <p className="text-xs uppercase tracking-wide text-gray-500">
                                        Failed
                                    </p>
                                    <p className="mt-2 text-2xl font-bold text-red-700">
                                        {statusTotals.failed || 0}
                                    </p>
                                </div>
                            </div>

                            {checkTypes.map(({ type, enabled }) =>
                                enabled ? (
                                    <MonitorHistoryHeatmap
                                        key={type}
                                        title={`${formatCheckTypeLabel(type)} Health`}
                                        description={`${selectedRange?.from} to ${selectedRange?.to} (${selectedRange?.timezone})`}
                                        fromDate={selectedRange?.from}
                                        toDate={selectedRange?.to}
                                        points={history.daily_metrics?.[type] || []}
                                    />
                                ) : (
                                    <div
                                        key={type}
                                        className="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-5"
                                    >
                                        <h3 className="text-base font-semibold text-gray-700">
                                            {`${formatCheckTypeLabel(type)} Health`}
                                        </h3>
                                        <p className="mt-1 text-sm text-gray-500">
                                            {formatCheckTypeLabel(type)} checks are not enabled
                                            for this monitor.
                                        </p>
                                    </div>
                                )
                            )}

                            <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                                <h3 className="text-base font-semibold text-gray-900">
                                    Recent Checks
                                </h3>
                                <div className="mt-4 overflow-x-auto">
                                    <table className="min-w-full text-sm">
                                        <thead>
                                            <tr className="text-left text-gray-500 border-b border-gray-200">
                                                <th className="py-2 pr-4 font-medium">Time</th>
                                                <th className="py-2 pr-4 font-medium">Type</th>
                                                <th className="py-2 pr-4 font-medium">Status</th>
                                                <th className="py-2 pr-4 font-medium">Message</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(history.recent_checks || []).length === 0 ? (
                                                <tr>
                                                    <td className="py-4 text-gray-500" colSpan={4}>
                                                        No checks recorded yet.
                                                    </td>
                                                </tr>
                                            ) : (
                                                (history.recent_checks || []).map((check) => {
                                                    const normalizedStatus =
                                                        normalizeCheckStatus(check.status);

                                                    return (
                                                        <tr
                                                            key={check.id}
                                                            className="border-b border-gray-100 last:border-b-0"
                                                        >
                                                            <td className="py-2 pr-4 text-gray-700 whitespace-nowrap">
                                                                {check.checked_at}
                                                            </td>
                                                            <td className="py-2 pr-4 text-gray-700">
                                                                {formatCheckTypeLabel(check.check_type)}
                                                            </td>
                                                            <td className="py-2 pr-4">
                                                                <Badge
                                                                    text={normalizedStatus}
                                                                    color={getCheckStatusBadgeColor(
                                                                        normalizedStatus
                                                                    )}
                                                                />
                                                            </td>
                                                            <td className="py-2 pr-4 text-gray-700">
                                                                {check.message ||
                                                                    check.failure_reason ||
                                                                    "No details"}
                                                            </td>
                                                        </tr>
                                                    );
                                                })
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </Authenticated>
    );
}
