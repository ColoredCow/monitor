import React from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, Link, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import { PlusIcon } from "@heroicons/react/24/solid";

export default function Index() {
    const { auth, organizations } = usePage().props;

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
                {organizations.map((org) => (
                    <div
                        key={org.id}
                        className="bg-white rounded-xl border border-gray-200 shadow-sm p-5 flex justify-between items-center"
                    >
                        <div>
                            <div className="font-semibold text-gray-900">{org.name}</div>
                            <div className="text-xs text-gray-500 mt-0.5">
                                {org.users_count} users · {org.monitors_count} monitors
                            </div>
                        </div>
                        <Link
                            href={route("organizations.edit", org.id)}
                            className="text-sm text-purple-600 hover:text-purple-800"
                        >
                            Rename
                        </Link>
                    </div>
                ))}
            </div>
        </Authenticated>
    );
}
