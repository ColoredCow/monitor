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
        <div className="w-full md:w-1/2 lg:w-1/3 p-4">
            <div className="glass premium-shadow rounded-3xl p-6 border border-white/40 transition-all duration-300 hover:scale-[1.02] hover:shadow-2xl">
                <div className="flex justify-between items-start mb-2">
                    <div className="flex flex-col">
                        <h3 className="font-bold text-lg text-gray-900 leading-tight">
                            {group.name}
                        </h3>
                        <div className="flex items-center mt-2">
                            <span className="px-3 py-1 bg-purple-50 text-purple-600 text-xs font-bold rounded-lg uppercase tracking-wider">
                                {monitorsCountText}
                            </span>
                        </div>
                    </div>
                    <div className="flex space-x-1">
                        <Link
                            href={route("groups.edit", group.id)}
                            className="p-2 rounded-xl text-gray-400 hover:bg-purple-100 hover:text-purple-600 transition-all"
                        >
                            <PencilIcon className="h-4.5 w-4.5" />
                        </Link>
                        {monitorsCount ? (
                            <div
                                className="p-2 rounded-xl text-gray-200 cursor-not-allowed"
                                title="Group has monitors"
                            >
                                <TrashIcon className="h-4.5 w-4.5" />
                            </div>
                        ) : (
                            <button
                                onClick={handleDelete}
                                className="p-2 rounded-xl text-gray-400 hover:bg-red-100 hover:text-red-600 transition-all"
                            >
                                <TrashIcon className="h-4.5 w-4.5" />
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
