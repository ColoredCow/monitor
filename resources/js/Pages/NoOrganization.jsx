import React from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";

export default function NoOrganization() {
    const { auth } = usePage().props;

    return (
        <Authenticated auth={auth}>
            <Head title="No organization" />
            <PageHeader>
                <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                    No organization yet
                </h1>
            </PageHeader>
            <div className="max-w-xl mx-auto py-12 px-6 text-center text-gray-600">
                You're not a member of any organization yet. Please contact your
                administrator to be added to one.
            </div>
        </Authenticated>
    );
}
