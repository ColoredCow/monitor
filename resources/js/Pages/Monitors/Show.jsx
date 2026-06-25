import React, { useMemo, useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, Link, router, usePage } from "@inertiajs/react";
import {
    ArrowTopRightOnSquareIcon,
    ArrowLeftIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
} from "@heroicons/react/24/outline";
import MonitorUptimeIcon from "@/Components/MonitorUptimeIcon";
import MonitorDomainIcon from "@/Components/MonitorDomainIcon";
import MonitorCheckIntervalIcon from "@/Components/MonitorCheckIntervalIcon";
import PageHeader from "@/Components/PageHeader";
import MonitorHistoryHeatmap from "@/Components/MonitorHistoryHeatmap";
import MonitorLiveStatus from "@/Components/MonitorLiveStatus";
import MonitorTodayBar from "@/Components/MonitorTodayBar";
import RecentChecksPanel from "@/Components/RecentChecksPanel";
import MonitorHistoryFilters from "@/Components/MonitorHistoryFilters";
import SummaryStats from "@/Components/SummaryStats";
import { buildHistoryParams } from "@/Utils/historyParams";

const CHECK_TYPE_LABELS = {
    uptime: "Uptime",
    domain: "Domain",
    certificate: "Certificate",
};

function formatCheckTypeLabel(checkType) {
    return CHECK_TYPE_LABELS[checkType] || checkType;
}

export default function Show(props) {
    const { monitor, features, graph, filters, summary, recentChecks } = usePage().props;
    const isHistoryEnabled = Boolean(features?.monitorHistory);
    const [graphPending, setGraphPending] = useState(false);

    // The graph is driven solely by ?year and is decoupled from the filters.
    const currentParams = useMemo(
        () => ({
            year: graph?.year,
            preset: filters?.preset,
            from: filters?.from,
            to: filters?.to,
            recent_type: recentChecks?.type || "uptime",
            recent_page: recentChecks?.pagination?.current_page || 1,
        }),
        [graph?.year, filters, recentChecks]
    );

    const [recentPending, setRecentPending] = useState(false);
    const [filtersPending, setFiltersPending] = useState(false);

    const handleApplyFilters = (change) => {
        // Timezone is resolved server-side to match how metrics were aggregated,
        // so we intentionally never send the browser timezone here.
        const overrides = { ...change, recent_page: 1 };

        router.get(
            route("monitors.show", monitor.id),
            buildHistoryParams(currentParams, overrides),
            {
                only: ["filters", "summary", "recentChecks"],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setFiltersPending(true),
                onFinish: () => setFiltersPending(false),
            }
        );
    };

    const goToYear = (targetYear) => {
        router.get(
            route("monitors.show", monitor.id),
            buildHistoryParams(currentParams, { year: targetYear }),
            {
                only: ["graph"],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setGraphPending(true),
                onFinish: () => setGraphPending(false),
            }
        );
    };

    const reloadRecentChecks = (overrides) => {
        router.get(
            route("monitors.show", monitor.id),
            buildHistoryParams(currentParams, overrides),
            {
                only: ["recentChecks"],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setRecentPending(true),
                onFinish: () => setRecentPending(false),
            }
        );
    };

    const handleRecentTabChange = (type) => {
        reloadRecentChecks({ recent_type: type, recent_page: 1 });
    };

    const handleRecentPageChange = (page) => {
        reloadRecentChecks({ recent_page: page });
    };

    return (
        <Authenticated auth={props.auth} errors={props.errors}>
            <Head title={monitor.name || "Monitor Details"} />

            <PageHeader>
                <div className="flex items-start gap-4">
                    <Link
                        href={route("monitors.index")}
                        className="mt-1 inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition-colors duration-150 ease-out hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:ring-offset-2 motion-reduce:transition-none"
                    >
                        <ArrowLeftIcon className="h-4 w-4" />
                        Back
                    </Link>
                    <div className="min-w-0">
                        <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                            {monitor.name}
                        </h1>
                        <div className="mt-1 flex items-center gap-1 text-sm text-gray-600">
                            <span className="truncate max-w-[35rem]">
                                {monitor.raw_url}
                            </span>
                            <a
                                href={monitor.raw_url}
                                target="_blank"
                                rel="noreferrer"
                                className="rounded text-gray-600 transition-colors duration-150 ease-out hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 motion-reduce:transition-none"
                                title="Open monitor URL"
                            >
                                <ArrowTopRightOnSquareIcon className="h-4 w-4" />
                            </a>
                        </div>
                        <MonitorLiveStatus monitor={monitor} />
                    </div>
                </div>
            </PageHeader>

            <div className="max-w-7xl mx-auto py-8 px-6 lg:px-8 space-y-6">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-gray-200 bg-white px-5 py-3 shadow-sm">
                    <span className="text-xs font-semibold uppercase tracking-wide text-gray-600">
                        Snapshot
                    </span>
                    <div className="flex items-center gap-2.5 flex-wrap">
                        <MonitorUptimeIcon monitor={monitor} />
                        <MonitorCheckIntervalIcon monitor={monitor} />
                        <MonitorDomainIcon monitor={monitor} />
                    </div>
                </div>

                <div className="bg-white rounded-xl p-6 border border-gray-200 shadow-sm">
                    <div className="mb-2 flex items-baseline justify-between gap-4">
                        <h2 className="text-sm font-semibold tracking-wide text-gray-500 uppercase">
                            Monitor History
                        </h2>
                        {filters?.timezone ? (
                            <span className="text-xs text-gray-500">
                                All times in {filters.timezone}
                            </span>
                        ) : null}
                    </div>

                    {!isHistoryEnabled ? (
                        <p className="text-sm text-gray-600">
                            History view is disabled. Set{" "}
                            <code>MONITOR_HISTORY_ENABLED=true</code> to enable rollout when
                            backend history ingestion is ready.
                        </p>
                    ) : !graph && !filters && !summary ? (
                        <p className="text-sm text-gray-600">
                            History is enabled, but no history payload is available for
                            this monitor yet.
                        </p>
                    ) : (
                        <div className="space-y-6">
                            {graph ? (
                                <section aria-label="Yearly health graphs" className="space-y-6">
                                    <div className="flex items-center justify-between gap-4">
                                        <h3 className="text-base font-semibold text-gray-900">
                                            Health by year
                                        </h3>
                                        <div className="flex items-center gap-2" aria-busy={graphPending}>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    goToYear(graph.year - 1)
                                                }
                                                disabled={
                                                    graphPending ||
                                                    graph.year <= Math.min(...graph.available_years)
                                                }
                                                className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500"
                                                aria-label="Previous year"
                                            >
                                                <ChevronLeftIcon className="h-4 w-4" />
                                            </button>
                                            <span className="min-w-[3.5rem] text-center text-sm font-semibold text-gray-900 tabular-nums">
                                                {graph.year}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    goToYear(graph.year + 1)
                                                }
                                                disabled={
                                                    graphPending ||
                                                    graph.year >= Math.max(...graph.available_years)
                                                }
                                                className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500"
                                                aria-label="Next year"
                                            >
                                                <ChevronRightIcon className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>

                                    {graph.check_types
                                        .filter(({ enabled }) => enabled)
                                        .map(({ type }) => {
                                            const series = graph.series?.[type];
                                            const typeSummary = series?.summary;

                                            return (
                                                <div key={type} className="space-y-3">
                                                    <p className="text-sm font-medium text-gray-700 tabular-nums">
                                                        {`${formatCheckTypeLabel(type)} · ${
                                                            typeSummary
                                                                ? Number(
                                                                      typeSummary.success_ratio
                                                                  ).toFixed(1)
                                                                : "0.0"
                                                        }% · ${(
                                                            typeSummary?.total_checks || 0
                                                        ).toLocaleString()} checks`}
                                                    </p>
                                                    <MonitorTodayBar
                                                        checkType={type}
                                                        checks={series?.today_checks || []}
                                                    />
                                                    <MonitorHistoryHeatmap
                                                        checkType={type}
                                                        title={`${formatCheckTypeLabel(type)} Health`}
                                                        description={`${graph.year} (${graph.timezone})`}
                                                        year={graph.year}
                                                        points={series?.daily_metrics || []}
                                                        todayIso={graph.today_iso || null}
                                                    />
                                                </div>
                                            );
                                        })}
                                </section>
                            ) : null}

                            <MonitorHistoryFilters
                                filters={filters}
                                pending={filtersPending}
                                onApply={handleApplyFilters}
                            />

                            <SummaryStats
                                summary={summary}
                                onViewAllTime={() => handleApplyFilters({ preset: "all" })}
                            />

                            <RecentChecksPanel
                                recentChecks={recentChecks}
                                checkTypes={graph?.check_types || []}
                                pending={recentPending}
                                onTabChange={handleRecentTabChange}
                                onPageChange={handleRecentPageChange}
                            />
                        </div>
                    )}
                </div>
            </div>
        </Authenticated>
    );
}
