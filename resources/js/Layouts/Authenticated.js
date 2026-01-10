import React, { useState } from "react";
import ApplicationLogo from "@/Components/ApplicationLogo";
import Dropdown from "@/Components/Dropdown";
import NavLink from "@/Components/NavLink";
import ResponsiveNavLink from "@/Components/ResponsiveNavLink";
import { Link } from "@inertiajs/react";

export default function Authenticated({ auth, children }) {
    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);

    return (
        <div className="min-h-screen bg-[#f8fafc] flex flex-col">
            <nav className="bg-white/80 backdrop-blur-md sticky top-0 z-50 border-b border-gray-200/50">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex justify-between h-20">
                        <div className="flex items-center space-x-12">
                            <div className="shrink-0 flex items-center transition-transform hover:scale-105">
                                <Link
                                    href="/"
                                    className="bg-purple-50 p-2 rounded-2xl"
                                >
                                    <ApplicationLogo className="block h-8 w-auto text-purple-600" />
                                </Link>
                                <span className="ml-3 font-bold text-xl tracking-tight text-gray-900 hidden lg:block">
                                    Monitor
                                </span>
                            </div>

                            <div className="hidden space-x-2 sm:flex items-center">
                                <NavLink
                                    href={route("monitors.index")}
                                    active={route().current("monitors.*")}
                                >
                                    Monitors
                                </NavLink>
                                <NavLink
                                    href={route("groups.index")}
                                    active={route().current("groups.*")}
                                >
                                    Groups
                                </NavLink>
                                <NavLink
                                    href={route("users.index")}
                                    active={route().current("users.*")}
                                >
                                    Users
                                </NavLink>
                            </div>
                        </div>

                        <div className="hidden sm:flex sm:items-center sm:ml-6">
                            <div className="ml-3 relative">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <button
                                            type="button"
                                            className="inline-flex items-center px-4 py-2 bg-gray-50 border border-transparent text-sm leading-4 font-semibold rounded-2xl text-gray-700 hover:bg-gray-100 focus:outline-none transition-all duration-200"
                                        >
                                            <div className="w-8 h-8 rounded-xl bg-linear-to-br from-purple-500 to-indigo-500 mr-2 flex items-center justify-center text-white text-xs font-bold uppercase shadow-sm">
                                                {auth.user.name.charAt(0)}
                                            </div>
                                            {auth.user.name}

                                            <svg
                                                className="ml-2 -mr-0.5 h-4 w-4 opacity-50"
                                                xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20"
                                                fill="currentColor"
                                            >
                                                <path
                                                    fillRule="evenodd"
                                                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                    clipRule="evenodd"
                                                />
                                            </svg>
                                        </button>
                                    </Dropdown.Trigger>

                                    <Dropdown.Content>
                                        <Dropdown.Link
                                            href={route("logout")}
                                            method="post"
                                            as="button"
                                        >
                                            Log Out
                                        </Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        </div>

                        <div className="-mr-2 flex items-center sm:hidden">
                            <button
                                onClick={() =>
                                    setShowingNavigationDropdown(
                                        (previousState) => !previousState
                                    )
                                }
                                className="inline-flex items-center justify-center p-2 rounded-xl text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none transition duration-150 ease-in-out"
                            >
                                <svg
                                    className="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        className={
                                            !showingNavigationDropdown
                                                ? "inline-flex"
                                                : "hidden"
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        className={
                                            showingNavigationDropdown
                                                ? "inline-flex"
                                                : "hidden"
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    className={
                        (showingNavigationDropdown ? "block" : "hidden") +
                        " sm:hidden"
                    }
                >
                    <div className="pt-2 pb-3 space-y-1">
                        <ResponsiveNavLink
                            href={route("monitors.index")}
                            active={route().current("monitors.index")}
                        >
                            Monitors
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route("groups.index")}
                            active={route().current("groups.index")}
                        >
                            Groups
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route("users.index")}
                            active={route().current("users.index")}
                        >
                            Users
                        </ResponsiveNavLink>
                    </div>

                    <div className="pt-4 pb-4 border-t border-gray-100">
                        <div className="px-4 flex items-center mb-4">
                            <div className="w-10 h-10 rounded-xl bg-purple-500 flex items-center justify-center text-white font-bold mr-3">
                                {auth.user.name.charAt(0)}
                            </div>
                            <div>
                                <div className="font-bold text-base text-gray-900">
                                    {auth.user.name}
                                </div>
                                <div className="font-medium text-sm text-gray-500">
                                    {auth.user.email}
                                </div>
                            </div>
                        </div>

                        <div className="space-y-1">
                            <ResponsiveNavLink
                                method="post"
                                href={route("logout")}
                                as="button"
                            >
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <main className="grow">{children}</main>
        </div>
    );
}
