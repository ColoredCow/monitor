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
        <div className="w-full md:w-1/2 lg:w-1/3 p-4">
            <div className="glass premium-shadow rounded-3xl p-6 border border-white/40 transition-all duration-300 hover:scale-[1.02] hover:shadow-2xl">
                <div className="flex justify-between items-start mb-4">
                    <div>
                        <h3 className="font-bold text-lg text-gray-900 leading-tight">
                            {monitor.name}
                        </h3>
                        <div className="flex items-center mt-1 text-sm">
                            <span className="text-gray-500 font-medium truncate max-w-[150px]">
                                {monitor.raw_url}
                            </span>
                            <a
                                className="ml-2 text-purple-400 hover:text-purple-600 transition-colors"
                                href={monitor.raw_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <ArrowTopRightOnSquareIcon className="h-4 w-4" />
                            </a>
                        </div>
                    </div>
                    <div className="flex space-x-1">
                        <Link
                            href={route("monitors.edit", monitor.id)}
                            className="p-2 rounded-xl text-gray-400 hover:bg-purple-100 hover:text-purple-600 transition-all"
                        >
                            <PencilIcon className="h-4.5 w-4.5" />
                        </Link>
                        <button
                            onClick={handleDelete}
                            className="p-2 rounded-xl text-gray-400 hover:bg-red-100 hover:text-red-600 transition-all"
                        >
                            <TrashIcon className="h-4.5 w-4.5" />
                        </button>
                    </div>
                </div>

                <div className="flex items-center space-x-3 pt-4 border-t border-gray-100/50">
                    <MonitorUptimeIcon monitor={monitor} />
                    <MonitorCheckIntervalIcon monitor={monitor} />
                    <MonitorDomainIcon monitor={monitor} />
                </div>
            </div>
        </div>
    );
}
