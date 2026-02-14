import React from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, Link, usePage } from "@inertiajs/react";
import {
    ArrowTopRightOnSquareIcon,
    ArrowLeftIcon,
} from "@heroicons/react/24/outline";
import MonitorUptimeIcon from "@/Components/MonitorUptimeIcon";
import MonitorDomainIcon from "@/Components/MonitorDomainIcon";
import MonitorCheckIntervalIcon from "@/Components/MonitorCheckIntervalIcon";
import PageHeader from "@/Components/PageHeader";

export default function Show(props) {
    const { monitor, features } = usePage().props;
    const isHistoryEnabled = Boolean(features?.monitorHistory);

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
                            <span className="truncate max-w-[35rem]">
                                {monitor.raw_url}
                            </span>
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
                    {isHistoryEnabled ? (
                        <p className="text-sm text-gray-600">
                            History view is enabled. Daily metrics and check logs
                            will be available in the next implementation phase.
                        </p>
                    ) : (
                        <p className="text-sm text-gray-600">
                            History view is disabled. Set{" "}
                            <code>MONITOR_HISTORY_ENABLED=true</code> to enable
                            rollout when backend history ingestion is ready.
                        </p>
                    )}
                </div>
            </div>
        </Authenticated>
    );
}
