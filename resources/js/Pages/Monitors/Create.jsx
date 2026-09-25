import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, usePage, router } from "@inertiajs/react";
import Select from "@/Components/Select";
import PageHeader from "@/Components/PageHeader";
import Label from "@/Components/Label";
import Input from "@/Components/Input";
import Checkbox from "@/Components/Checkbox";
import Button from "@/Components/Button";
import MonitorCreditImpact from "@/Components/MonitorCreditImpact";

export default function Create(props) {
    const { groups, auth } = usePage().props;

    const [form, setForm] = useState({
        name: "",
        url: "",
        monitorUptime: true,
        monitorDomain: true,
        uptimeCheckInterval: "2",
        monitorGroupId: "",
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
        router.post(route("monitors.store"), form);
    };

    return (
        <Authenticated auth={props.auth} errors={props.errors}>
            <Head title="Create Monitor" />

            <PageHeader>
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                        Create Monitor
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Configure a new endpoint to monitor
                    </p>
                </div>
            </PageHeader>

            <div className="max-w-3xl mx-auto py-8 px-6 lg:px-8">
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div className="p-8">
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div className="space-y-6">
                                <div>
                                    <Label
                                        forInput="name"
                                        value="Name of Monitor"
                                    />
                                    <Input
                                        name="name"
                                        value={form.name}
                                        required
                                        placeholder="e.g. Production API"
                                        handleChange={handleChange}
                                    />
                                </div>

                                <div>
                                    <Label forInput="url" value="Target URL" />
                                    <Input
                                        name="url"
                                        type="url"
                                        value={form.url}
                                        required
                                        placeholder="https://example.com"
                                        handleChange={handleChange}
                                    />
                                    <p className="mt-1.5 text-xs text-gray-500 ml-0.5">
                                        Must include protocol (http:// or https://)
                                    </p>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
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
                            </div>

                            <div className="pt-6 border-t border-gray-200 space-y-4">
                                <div className="flex flex-col sm:flex-row gap-4">
                                    <label className="flex items-center gap-3 cursor-pointer group">
                                        <Checkbox
                                            name="monitorUptime"
                                            checked={form.monitorUptime}
                                            handleChange={handleChange}
                                        />
                                        <span className="text-sm font-medium text-gray-700 group-hover:text-gray-900 transition-colors">
                                            Monitor Uptime
                                        </span>
                                    </label>
                                    <label className="flex items-center gap-3 cursor-pointer group">
                                        <Checkbox
                                            name="monitorDomain"
                                            checked={form.monitorDomain}
                                            handleChange={handleChange}
                                        />
                                        <span className="text-sm font-medium text-gray-700 group-hover:text-gray-900 transition-colors">
                                            Monitor Domain
                                        </span>
                                    </label>
                                </div>

                                <MonitorCreditImpact credits={auth.credits} burnBefore={0} form={form} />
                            </div>

                            <div className="pt-6 border-t border-gray-200">
                                <Button>
                                    Create Monitor
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Authenticated>
    );
}
