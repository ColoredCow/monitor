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
    });

    const handleChange = (e) => {
        const { name, value, type, checked } = e.target;
        console.log({ name, value, type, checked });
        setForm((prevState) => ({
            ...prevState,
            [name]: type === "checkbox" ? checked : value,
        }));
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        router.post(route("groups.store"), form);
    };

    return (
        <Authenticated auth={props.auth} errors={props.errors}>
            <Head title="Create Group" />

            <PageHeader>
                <h2 className="font-bold text-3xl text-gray-900 leading-tight">
                    Create Group
                </h2>
                <p className="text-gray-500 font-medium mt-1">
                    Organize your monitors into logical sets
                </p>
            </PageHeader>

            <div className="max-w-xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
                <div className="glass premium-shadow rounded-3xl overflow-hidden border border-white/40">
                    <div className="p-8 sm:p-10">
                        <form onSubmit={handleSubmit} className="space-y-8">
                            <div>
                                <Label forInput="name" value="Name of Group" />
                                <Input
                                    name="name"
                                    value={form.name}
                                    required
                                    handleChange={handleChange}
                                    placeholder="e.g. Production Cluster"
                                />
                            </div>
                            <div className="pt-4">
                                <Button className="w-full h-12 justify-center">
                                    Create Group
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Authenticated>
    );
}
