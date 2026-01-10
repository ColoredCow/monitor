import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";
import { router } from "@inertiajs/react";

export default function Create(props) {
    const [form, setForm] = useState({
        name: "",
        email: "",
        password: "",
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
        router.post(route("users.store"), form);
    };

    return (
        <Authenticated auth={props.auth} errors={props.errors}>
            <Head title="Create User" />

            <PageHeader>
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                        Create User
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Add a new team member to the platform
                    </p>
                </div>
            </PageHeader>

            <div className="max-w-xl mx-auto py-8 px-6 lg:px-8">
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div className="p-8">
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div>
                                <Label forInput="name" value="Full Name" />
                                <Input
                                    name="name"
                                    value={form.name}
                                    required
                                    handleChange={handleChange}
                                    placeholder="e.g. John Doe"
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
                                    placeholder="john@example.com"
                                />
                            </div>
                            <div>
                                <Label forInput="password" value="Password" />
                                <Input
                                    name="password"
                                    type="password"
                                    value={form.password}
                                    required
                                    handleChange={handleChange}
                                    placeholder="••••••••"
                                />
                            </div>
                            <div className="pt-6 border-t border-gray-200">
                                <Button>
                                    Create User
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Authenticated>
    );
}
