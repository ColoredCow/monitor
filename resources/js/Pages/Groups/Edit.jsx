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
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                        Edit Group
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Modify group details for {group.name}
                    </p>
                </div>
            </PageHeader>

            <div className="max-w-xl mx-auto py-8 px-6 lg:px-8">
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div className="p-8">
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div>
                                <Label forInput="name" value="Name of Group" />
                                <Input
                                    name="name"
                                    value={form.name}
                                    required
                                    handleChange={handleChange}
                                />
                            </div>
                            <div className="pt-6 border-t border-gray-200">
                                <Button>
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
