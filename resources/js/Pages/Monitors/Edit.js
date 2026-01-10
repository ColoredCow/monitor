import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";
import Checkbox from "@/Components/Checkbox";
import { router } from "@inertiajs/react";

import Select from "@/Components/Select";

export default function Edit(props) {
    const { monitor, groups } = usePage().props;

    const [form, setForm] = useState({
        name: monitor.name,
        url: monitor.raw_url,
        monitorUptime: monitor.uptime_check_enabled,
        monitorDomain: monitor.domain_check_enabled,
        monitorGroupId: monitor.group_id ?? "",
        uptimeCheckInterval: monitor.uptime_check_interval_in_minutes,
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
        router.put(route("monitors.update", monitor.id), form);
    };

    return (
        <Authenticated auth={props.auth} errors={props.errors}>
            <Head title={`Edit ${monitor.name}`} />

            <PageHeader>
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="font-bold text-3xl text-gray-900 leading-tight">
                            Edit Monitor
                        </h2>
                        <p className="text-gray-500 font-medium mt-1">
                            Update configuration for {monitor.name}
                        </p>
                    </div>
                </div>
            </PageHeader>

            <div className="max-w-3xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
                <div className="glass premium-shadow rounded-3xl overflow-hidden border border-white/40">
                    <div className="p-8 sm:p-10">
                        <form onSubmit={handleSubmit} className="space-y-8">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div className="md:col-span-2">
                                    <Label
                                        forInput="name"
                                        value="Name of Monitor"
                                    />
                                    <Input
                                        name="name"
                                        value={form.name}
                                        required
                                        handleChange={handleChange}
                                    />
                                </div>

                                <div className="md:col-span-2">
                                    <Label forInput="url" value="Target URL" />
                                    <Input
                                        name="url"
                                        type="url"
                                        value={form.url}
                                        required
                                        handleChange={handleChange}
                                    />
                                </div>

                                <div>
                                    <Label
                                        forInput="uptimeCheckInterval"
                                        value="Check Interval"
                                    />
                                    <Select
                                        name="uptimeCheckInterval"
                                        value={form.uptimeCheckInterval}
                                        handleChange={handleChange}
                                    >
                                        <option value="1">Every Minute</option>
                                        <option value="2">2 Minutes</option>
                                        <option value="5">5 Minutes</option>
                                        <option value="10">10 Minutes</option>
                                        <option value="20">20 Minutes</option>
                                        <option value="30">30 Minutes</option>
                                        <option value="60">1 Hour</option>
                                    </Select>
                                </div>

                                <div>
                                    <Label
                                        forInput="monitorGroupId"
                                        value="Assign to Group"
                                    />
                                    <Select
                                        name="monitorGroupId"
                                        value={form.monitorGroupId}
                                        handleChange={handleChange}
                                    >
                                        <option value="">No Group</option>
                                        {groups.map((group, index) => (
                                            <option
                                                value={group.id}
                                                key={index}
                                            >
                                                {group.name}
                                            </option>
                                        ))}
                                    </Select>
                                </div>
                            </div>

                            <div className="pt-4 border-t border-gray-100 flex flex-wrap gap-6">
                                <label className="flex items-center cursor-pointer group">
                                    <Checkbox
                                        name="monitorUptime"
                                        checked={form.monitorUptime}
                                        handleChange={handleChange}
                                    />
                                    <span className="ml-3 text-sm font-semibold text-gray-700 group-hover:text-purple-600 transition-colors">
                                        Monitor Uptime
                                    </span>
                                </label>
                                <label className="flex items-center cursor-pointer group">
                                    <Checkbox
                                        name="monitorDomain"
                                        checked={form.monitorDomain}
                                        handleChange={handleChange}
                                    />
                                    <span className="ml-3 text-sm font-semibold text-gray-700 group-hover:text-purple-600 transition-colors">
                                        Monitor Domain
                                    </span>
                                </label>
                            </div>

                            <div className="pt-6">
                                <Button className="w-full sm:w-auto h-12">
                                    Update Monitor
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Authenticated>
    );
}
