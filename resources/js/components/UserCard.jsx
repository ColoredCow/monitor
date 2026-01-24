import React from "react";
import { Link, router } from "@inertiajs/react";
import { PencilIcon, TrashIcon } from "@heroicons/react/24/outline";

export default function UserCard({ user }) {
    const handleDelete = (e) => {
        e.preventDefault();
        if (confirm(`Are you sure you want to remove the user ${user.name}?`)) {
            router.delete(route("users.destroy", user.id));
        }
    };

    return (
        <div className="w-full md:w-1/2 lg:w-1/3 px-3 mb-6">
            <div className="bg-white rounded-xl p-5 border border-gray-200 shadow-sm hover:shadow-md transition-all duration-200 h-full flex flex-col">
                <div className="flex justify-between items-center">
                    <div className="flex items-center flex-1 min-w-0">
                        <div className="w-10 h-10 rounded-lg bg-gradient-to-br from-purple-600 to-indigo-600 flex items-center justify-center text-white text-sm font-semibold uppercase mr-3 flex-shrink-0 shadow-sm">
                            {user.name.charAt(0)}
                        </div>
                        <div className="flex flex-col min-w-0 flex-1">
                            <h3 className="font-semibold text-sm text-gray-900 leading-tight truncate">
                                {user.name}
                            </h3>
                            <span className="text-xs text-gray-500 font-medium mt-0.5 truncate">
                                {user.email}
                            </span>
                        </div>
                    </div>
                    <div className="flex gap-1 ml-3 flex-shrink-0">
                        <Link
                            href={route("users.edit", user.id)}
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
            </div>
        </div>
    );
}
