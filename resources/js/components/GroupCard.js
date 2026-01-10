import React from "react";
import { Link, router } from "@inertiajs/react";
import { PencilIcon, TrashIcon } from "@heroicons/react/24/outline";

export default function GroupCard({ group }) {
    const monitorsCount = group.monitors?.length ?? 0;
    const monitorsCountText =
        monitorsCount == 1
            ? `${monitorsCount} monitor`
            : `${monitorsCount} monitors`;

    const handleDelete = (e) => {
        e.preventDefault();
        if (monitorsCount) return;
        if (
            confirm(`Are you sure you want to remove the group ${group.name}?`)
        ) {
            router.delete(route("groups.destroy", group.id));
        }
    };

    return (
        <div className="w-full md:w-1/2 lg:w-1/3 px-3 mb-6">
            <div className="bg-white rounded-xl p-5 border border-gray-200 shadow-sm hover:shadow-md transition-all duration-200 h-full flex flex-col">
                <div className="flex justify-between items-start mb-4">
                    <div className="flex-1 min-w-0">
                        <h3 className="font-semibold text-base text-gray-900 leading-tight mb-2">
                            {group.name}
                        </h3>
                        <div className="flex items-center">
                            <span className="inline-flex items-center px-2.5 py-1 bg-purple-50 text-purple-700 text-xs font-medium rounded-lg border border-purple-200">
                                {monitorsCountText}
                            </span>
                        </div>
                    </div>
                    <div className="flex gap-1 ml-3 flex-shrink-0">
                        <Link
                            href={route("groups.edit", group.id)}
                            className="p-2 rounded-lg text-gray-400 hover:bg-purple-50 hover:text-purple-600 transition-colors"
                        >
                            <PencilIcon className="h-4 w-4" />
                        </Link>
                        {monitorsCount ? (
                            <div
                                className="p-2 rounded-lg text-gray-300 cursor-not-allowed"
                                title="Group has monitors"
                            >
                                <TrashIcon className="h-4 w-4" />
                            </div>
                        ) : (
                            <button
                                onClick={handleDelete}
                                className="p-2 rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600 transition-colors"
                            >
                                <TrashIcon className="h-4 w-4" />
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
