import React from "react";
import MonitorUptimeIcon from "./MonitorUptimeIcon";
import MonitorDomainIcon from "./MonitorDomainIcon";
import MonitorCheckIntervalIcon from "./MonitorCheckIntervalIcon";
import { Link, router } from "@inertiajs/react";
import {
    ArrowTopRightOnSquareIcon,
    PencilIcon,
    TrashIcon,
} from "@heroicons/react/24/outline";

export default function MonitorCard({ monitor }) {
    const handleDelete = (e) => {
        e.preventDefault();
        if (
            confirm(
                `Are you sure you want to remove ${monitor.name} (${monitor.raw_url}) from monitoring?`
            )
        ) {
            router.delete(route("monitors.destroy", monitor.id));
        }
    };

    return (
        <div className="w-full md:w-1/2 lg:w-1/3 px-3 mb-6">
            <div className="bg-white rounded-xl p-5 border border-gray-200 shadow-sm hover:shadow-md transition-all duration-200 h-full flex flex-col">
                <div className="flex justify-between items-start mb-4">
                    <div className="flex-1 min-w-0">
                        <h3 className="font-semibold text-base text-gray-900 leading-tight mb-1.5 truncate">
                            {monitor.name}
                        </h3>
                        <div className="flex items-center gap-2">
                            <span className="text-sm text-gray-500 truncate flex-1 min-w-0">
                                {monitor.raw_url}
                            </span>
                            <a
                                className="flex-shrink-0 text-gray-400 hover:text-purple-600 transition-colors p-1 rounded hover:bg-purple-50"
                                href={monitor.raw_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <ArrowTopRightOnSquareIcon className="h-4 w-4" />
                            </a>
                        </div>
                    </div>
                    <div className="flex gap-1 ml-3 flex-shrink-0">
                        <Link
                            href={route("monitors.edit", monitor.id)}
                            className="p-2 rounded-lg text-gray-400 hover:bg-purple-50 hover:text-purple-600 transition-colors"
                        >
                            <PencilIcon className="h-4 w-4" />
                        </Link>
                        <button
                            onClick={handleDelete}
                            className="p-2 rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600 transition-colors"
                        >
                            <TrashIcon className="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div className="flex items-center gap-2.5 pt-4 mt-auto border-t border-gray-100 flex-wrap">
                    <MonitorUptimeIcon monitor={monitor} />
                    <MonitorCheckIntervalIcon monitor={monitor} />
                    <MonitorDomainIcon monitor={monitor} />
                </div>
            </div>
        </div>
    );
}
