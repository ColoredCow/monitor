import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, router, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";

export default function Create() {
    const { auth } = usePage().props;
    const [form, setForm] = useState({
        name: "",
        admin_name: "",
        admin_email: "",
        admin_password: "",
    });

    const handleChange = (e) =>
        setForm((p) => ({ ...p, [e.target.name]: e.target.value }));

    const handleSubmit = (e) => {
        e.preventDefault();
        router.post(route("organizations.store"), form);
    };

    return (
        <Authenticated auth={auth}>
            <Head title="Onboard organization" />
            <PageHeader>
                <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                    Onboard organization
                </h1>
            </PageHeader>
            <div className="max-w-xl mx-auto py-8 px-6 lg:px-8">
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <Label forInput="name" value="Organization name" />
                            <Input name="name" value={form.name} required handleChange={handleChange} />
                        </div>
                        <div className="pt-6 border-t border-gray-200 space-y-6">
                            <p className="text-sm font-medium text-gray-700">First admin</p>
                            <div>
                                <Label forInput="admin_name" value="Admin name" />
                                <Input name="admin_name" value={form.admin_name} required handleChange={handleChange} />
                            </div>
                            <div>
                                <Label forInput="admin_email" value="Admin email" />
                                <Input name="admin_email" type="email" value={form.admin_email} required handleChange={handleChange} />
                            </div>
                            <div>
                                <Label forInput="admin_password" value="Admin password" />
                                <Input name="admin_password" type="password" value={form.admin_password} required handleChange={handleChange} />
                            </div>
                        </div>
                        <div className="pt-6 border-t border-gray-200">
                            <Button>Create organization</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Authenticated>
    );
}
