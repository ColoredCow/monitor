import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, router, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";

export default function Edit() {
    const { auth, organization } = usePage().props;
    const [form, setForm] = useState({ name: organization.name });

    const handleChange = (e) =>
        setForm((p) => ({ ...p, [e.target.name]: e.target.value }));

    const handleSubmit = (e) => {
        e.preventDefault();
        router.put(route("organizations.update", organization.id), form);
    };

    return (
        <Authenticated auth={auth}>
            <Head title="Rename organization" />
            <PageHeader>
                <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                    Rename organization
                </h1>
            </PageHeader>
            <div className="max-w-xl mx-auto py-8 px-6 lg:px-8">
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <Label forInput="name" value="Organization name" />
                            <Input name="name" value={form.name} required handleChange={handleChange} />
                        </div>
                        <div className="pt-6 border-t border-gray-200">
                            <Button>Save</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Authenticated>
    );
}
