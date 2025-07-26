import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head } from "@inertiajs/react";
import PageHeader from "@/components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";
import { router } from '@inertiajs/react'

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
        router.post(route('users.store'), form);
    };

    return (
        <Authenticated auth={props.auth} errors={props.errors}>
            <Head title="Create User" />

            <PageHeader>
                <h2 className="font-bold text-xl text-purple-600 leading-tight uppercase">
                    Create User
                </h2>
            </PageHeader>

            <div className="mx-auto py-12 w-1/2">
                <form onSubmit={handleSubmit}>
                    <div className="mb-4">
                        <Label forInput="name">Name</Label>
                        <Input name="name" value={form.name} required handleChange={handleChange} />
                    </div>
                    <div className="mb-4">
                        <Label forInput="email">Email</Label>
                        <Input name="email" type="email" value={form.email} required handleChange={handleChange} />
                    </div>
                    <div className="mb-4">
                        <Label forInput="password">Password</Label>
                        <Input name="password" type="password" value={form.password} required handleChange={handleChange} />
                    </div>
                    <div>
                        <Button>Create</Button>
                    </div>
                </form>
            </div>
        </Authenticated>
    );
}
