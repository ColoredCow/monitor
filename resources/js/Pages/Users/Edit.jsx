import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";
import { router } from "@inertiajs/react";

export default function Edit(props) {
    const { user, canEditProfile = true } = usePage().props;

    const [form, setForm] = useState({
        name: user.name,
        email: user.email,
        password: "",
        role: user.role,
    });

    const handleChange = (e) => {
        const { name, value, type, checked } = e.target;
        setForm((prevState) => ({
            ...prevState,
            [name]: type === "checkbox" ? checked : value,
        }));
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        router.put(route("users.update", user.id), form);
    };

    return (
        <Authenticated auth={props.auth} errors={props.errors}>
            <Head title="Edit User" />

            <PageHeader>
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                        Edit User
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Update profile details for {user.name}
                    </p>
                </div>
            </PageHeader>

            <div className="max-w-xl mx-auto py-8 px-6 lg:px-8">
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div className="p-8">
                        <form onSubmit={handleSubmit} className="space-y-6">
                            {canEditProfile ? (
                                <>
                                    <div>
                                        <Label forInput="name" value="Full Name" />
                                        <Input
                                            name="name"
                                            value={form.name}
                                            required
                                            handleChange={handleChange}
                                        />
                                    </div>
                                    <div>
                                        <Label forInput="email" value="Email Address" />
                                        <Input
                                            name="email"
                                            type="email"
                                            value={form.email}
                                            required
                                            handleChange={handleChange}
                                        />
                                    </div>
                                    <div>
                                        <Label
                                            forInput="password"
                                            value="New Password"
                                        />
                                        <Input
                                            name="password"
                                            type="password"
                                            value={form.password}
                                            handleChange={handleChange}
                                            placeholder="Leave blank to keep current"
                                        />
                                    </div>
                                </>
                            ) : (
                                <div className="rounded-lg bg-gray-50 border border-gray-200 text-sm text-gray-600 px-4 py-3">
                                    {user.name} ({user.email}) also belongs to other
                                    organizations, so their profile is managed there. You
                                    can change their role in this organization below.
                                </div>
                            )}
                            <div>
                                <Label forInput="role" value="Role" />
                                <select
                                    name="role"
                                    value={form.role}
                                    onChange={handleChange}
                                    className="mt-1 block w-full rounded-lg border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm"
                                >
                                    <option value="member">Member (view only)</option>
                                    <option value="admin">Admin (full access)</option>
                                </select>
                            </div>
                            <div className="pt-6 border-t border-gray-200">
                                <Button>
                                    Update User
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Authenticated>
    );
}
