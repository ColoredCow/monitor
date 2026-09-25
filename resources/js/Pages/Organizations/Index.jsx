import React from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, Link, router, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import { PlusIcon } from "@heroicons/react/24/solid";

export default function Index() {
    const {
        auth,
        organizations,
        deletedOrganizations = [],
        purgeAfterDays = 60,
        status,
        errors = {},
    } = usePage().props;

    const handleDelete = (org) => {
        if (
            confirm(
                `Delete "${org.name}"? Its ${org.monitors_count} monitors, groups, and members whose only organization this is will be soft-deleted. It can be restored for ${purgeAfterDays} days.`
            )
        ) {
            router.delete(route("organizations.destroy", org.id));
        }
    };

    const handleRestore = (org) => {
        router.post(route("organizations.restore", org.id));
    };

    return (
        <Authenticated auth={auth}>
            <Head title="Organizations" />
            <PageHeader>
                <div className="flex justify-between items-center">
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                        Organizations
                    </h1>
                    <Link href={route("organizations.create")}>
                        <Button>
                            <PlusIcon className="h-4 w-4" />
                            <span>Onboard</span>
                        </Button>
                    </Link>
                </div>
            </PageHeader>
            <div className="max-w-3xl mx-auto py-8 px-6 lg:px-8 space-y-3">
                {status && (
                    <div className="rounded-lg bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3">
                        {status}
                    </div>
                )}
                {errors.restore && (
                    <div className="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
                        {errors.restore}
                    </div>
                )}

                {organizations.map((org) => (
                    <div
                        key={org.id}
                        className="bg-white rounded-xl border border-gray-200 shadow-sm p-5 flex justify-between items-center"
                    >
                        <div>
                            <div className="font-semibold text-gray-900">{org.name}</div>
                            <div className="text-xs text-gray-500 mt-0.5">
                                {org.users_count} users · {org.monitors_count} monitors ·{" "}
                                {(org.credit_balance ?? 0).toLocaleString()} credits
                            </div>
                        </div>
                        <div className="flex items-center gap-4">
                            <Link
                                href={route("organizations.credits.show", org.id)}
                                className="text-sm text-purple-600 hover:text-purple-800"
                            >
                                Credits
                            </Link>
                            <Link
                                href={route("organizations.edit", org.id)}
                                className="text-sm text-purple-600 hover:text-purple-800"
                            >
                                Rename
                            </Link>
                            <button
                                type="button"
                                onClick={() => handleDelete(org)}
                                className="text-sm text-red-600 hover:text-red-800"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                ))}

                {deletedOrganizations.length > 0 && (
                    <div className="pt-8">
                        <h2 className="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">
                            Deleted
                        </h2>
                        <div className="space-y-3">
                            {deletedOrganizations.map((org) => (
                                <div
                                    key={org.id}
                                    className="bg-gray-50 rounded-xl border border-dashed border-gray-300 p-5 flex justify-between items-center"
                                >
                                    <div>
                                        <div className="font-semibold text-gray-500">
                                            {org.name}
                                        </div>
                                        <div className="text-xs text-gray-400 mt-0.5">
                                            Deleted {org.deleted_at} ·{" "}
                                            {org.days_until_purge} days until permanent
                                            deletion
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => handleRestore(org)}
                                        className="text-sm text-purple-600 hover:text-purple-800"
                                    >
                                        Restore
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </Authenticated>
    );
}
