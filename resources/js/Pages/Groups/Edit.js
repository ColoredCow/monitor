import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";
import Checkbox from "@/Components/Checkbox";
import { router } from "@inertiajs/react";

export default function Edit(props) {
    const { group } = usePage().props;

    const [form, setForm] = useState({
        name: group.name,
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
        router.put(route("groups.update", group.id), form);
    };

    return (
        <Authenticated auth={props.auth} errors={props.errors}>
            <Head title="Edit Group" />

            <PageHeader>
                <h2 className="font-bold text-3xl text-gray-900 leading-tight">
                    Edit Group
                </h2>
                <p className="text-gray-500 font-medium mt-1">
                    Modify group details for {group.name}
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
                                />
                            </div>
                            <div className="pt-4">
                                <Button className="w-full h-12 justify-center">
                                    Update Group
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Authenticated>
    );
}
