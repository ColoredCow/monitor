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
                <h2 className="font-bold text-3xl text-gray-900 leading-tight">
                    Create User
                </h2>
                <p className="text-gray-500 font-medium mt-1">
                    Add a new team member to the platform
                </p>
            </PageHeader>

            <div className="max-w-xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
                <div className="glass premium-shadow rounded-3xl overflow-hidden border border-white/40">
                    <div className="p-8 sm:p-10">
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
                            <div className="pt-4">
                                <Button className="w-full h-12 justify-center">
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
