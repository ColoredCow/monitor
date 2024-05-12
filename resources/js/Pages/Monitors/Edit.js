import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, usePage } from "@inertiajs/react";
import PageHeader from "@/components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";
import Checkbox from "@/Components/Checkbox";
import { router } from '@inertiajs/react';

export default function Create(props) {

    const { monitor } = usePage().props;

    const [form, setForm] = useState({
        name: monitor.name,
        url: monitor.raw_url,
        monitorUptime: monitor.uptime_check_enabled,
        uptimeCheckInterval: monitor.uptime_check_interval_in_minutes,
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
        router.put(`/monitors/${monitor.id}`, form);
    };

    return (
        <Authenticated auth={props.auth} errors={props.errors}>
            <Head title="Edit Monitor" />

            <PageHeader>
                <h2 className="font-bold text-xl text-purple-600 leading-tight uppercase">
                    Edit Monitor
                </h2>
            </PageHeader>

            <div className="mx-auto py-12 w-1/2">
                <form onSubmit={handleSubmit}>
                    <div className="mb-4">
                        <Label forInput="name">Name of Monitor</Label>
                        <Input name="name" value={form.name} required handleChange={handleChange} />
                    </div>
                    <div className="mb-4">
                        <Label forInput="url">URL</Label>
                        <Input name="url" type="url" value={form.url} required handleChange={handleChange} />
                        <small className="text-gray-500">
                            Example: https://coloredcow.com
                        </small>
                    </div>
                    <div className="mb-4">
                        <label className="inline-flex items-center">
                            <Checkbox
                                name="monitorUptime"
                                checked={form.monitorUptime}
                                handleChange={handleChange}
                            />
                            <span className="ml-2">Monitor Uptime</span>
                        </label>
                    </div>
                    <div className="mb-4">
                        <label htmlFor="uptimeCheckInterval" className="block font-medium">
                            Uptime Check Interval
                        </label>
                        <select
                            id="uptimeCheckInterval"
                            name="uptimeCheckInterval"
                            value={form.uptimeCheckInterval}
                            className="mt-1 p-2 border border-gray-300 rounded w-full"
                            onChange={handleChange}
                        >
                            <option value="1">1 minute</option>
                            <option value="2">2 minutes</option>
                            <option value="5">5 minutes</option>
                            <option value="10">10 minutes</option>
                            <option value="20">20 minutes</option>
                            <option value="30">30 minutes</option>
                            <option value="60">1 hour</option>
                        </select>
                    </div>
                    <div>
                        <Button>Update</Button>
                    </div>
                </form>
            </div>
        </Authenticated>
    );
}
