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
        <div className="w-full md:w-1/2 lg:w-1/3 p-4">
            <div className="glass premium-shadow rounded-3xl p-6 border border-white/40 transition-all duration-300 hover:scale-[1.02] hover:shadow-2xl">
                <div className="flex justify-between items-center">
                    <div className="flex items-center">
                        <div className="w-12 h-12 rounded-2xl bg-linear-to-br from-purple-500/10 to-indigo-500/10 flex items-center justify-center text-purple-600 font-bold text-lg mr-4 border border-purple-100">
                            {user.name.charAt(0)}
                        </div>
                        <div className="flex flex-col">
                            <h3 className="font-bold text-gray-900 leading-tight">
                                {user.name}
                            </h3>
                            <span className="text-sm text-gray-500 font-medium mt-0.5">
                                {user.email}
                            </span>
                        </div>
                    </div>
                    <div className="flex space-x-1">
                        <Link
                            href={route("users.edit", user.id)}
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
            </div>
        </div>
    );
}
