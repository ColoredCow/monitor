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
        <div className="min-h-screen bg-gray-50 flex flex-col">
            <nav className="bg-white border-b border-gray-200 sticky top-0 z-50">
                <div className="max-w-7xl mx-auto px-6 lg:px-8">
                    <div className="flex justify-between items-center h-16">
                        <div className="flex items-center gap-10">
                            <Link
                                href="/"
                                className="flex items-center gap-3 transition-opacity hover:opacity-80"
                            >
                                <ApplicationLogo className="block h-6 w-auto text-white" />
                                <span className="font-bold text-lg tracking-tight text-gray-900 hidden lg:block">
                                    ColoredCow Monitor
                                </span>
                            </Link>

                            <div className="hidden sm:flex items-center gap-1">
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

                        <div className="hidden sm:flex sm:items-center">
                            <div className="relative">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <button
                                            type="button"
                                            className="inline-flex items-center gap-2.5 px-3 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 focus:outline-none transition-colors duration-150 rounded-lg hover:bg-gray-50"
                                        >
                                            <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-600 to-indigo-600 flex items-center justify-center text-white text-xs font-semibold uppercase shadow-sm">
                                                {auth.user.name.charAt(0)}
                                            </div>
                                            <span className="hidden md:block">{auth.user.name}</span>
                                            <svg
                                                className="h-4 w-4 text-gray-400"
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

                        <div className="flex items-center sm:hidden">
                            <button
                                onClick={() =>
                                    setShowingNavigationDropdown(
                                        (previousState) => !previousState
                                    )
                                }
                                className="inline-flex items-center justify-center p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none transition-colors duration-150"
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
                        " sm:hidden border-t border-gray-200"
                    }
                >
                    <div className="pt-2 pb-2">
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

                    <div className="pt-4 pb-3 border-t border-gray-200">
                        <div className="px-4 flex items-center gap-3 mb-4">
                            <div className="w-10 h-10 rounded-lg bg-gradient-to-br from-purple-600 to-indigo-600 flex items-center justify-center text-white text-xs font-semibold uppercase shadow-sm">
                                {auth.user.name.charAt(0)}
                            </div>
                            <div>
                                <div className="font-semibold text-sm text-gray-900">
                                    {auth.user.name}
                                </div>
                                <div className="font-medium text-xs text-gray-500 mt-0.5">
                                    {auth.user.email}
                                </div>
                            </div>
                        </div>

                        <div>
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
