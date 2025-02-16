import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, usePage } from "@inertiajs/react";
import PageHeader from "@/components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";
import Checkbox from "@/Components/Checkbox";
import { router } from '@inertiajs/react';

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
        router.put(`/groups/${group.id}`, form);
    };

    return (
        <Authenticated auth={props.auth} errors={props.errors}>
            <Head title="Edit Group" />

            <PageHeader>
                <h2 className="font-bold text-xl text-purple-600 leading-tight uppercase">
                    Edit Group
                </h2>
            </PageHeader>

            <div className="mx-auto py-12 w-1/2">
                <form onSubmit={handleSubmit}>
                    <div className="mb-4">
                        <Label forInput="name">Name of Group</Label>
                        <Input name="name" value={form.name} required handleChange={handleChange} />
                    </div>
                    <div>
                        <Button>Update</Button>
                    </div>
                </form>
            </div>
        </Authenticated>
    );
}
