import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, router, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";
import Checkbox from "@/Components/Checkbox";
import ValidationErrors from "@/Components/ValidationErrors";

export default function Edit() {
    const { auth, organization, errors } = usePage().props;
    const [form, setForm] = useState({
        name: organization.name,
        slack_webhook_url: "",
        remove_slack_webhook: false,
    });

    const handleChange = (e) =>
        setForm((p) => ({
            ...p,
            [e.target.name]: e.target.type === "checkbox" ? e.target.checked : e.target.value,
        }));

    const handleSubmit = (e) => {
        e.preventDefault();
        router.put(route("organizations.update", organization.id), form);
    };

    return (
        <Authenticated auth={auth}>
            <Head title="Edit organization" />
            <PageHeader>
                <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                    Edit organization
                </h1>
            </PageHeader>
            <div className="max-w-xl mx-auto py-8 px-6 lg:px-8">
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
                    <ValidationErrors errors={errors} />
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <Label forInput="name" value="Organization name" />
                            <Input name="name" value={form.name} required handleChange={handleChange} />
                        </div>
                        <div className="pt-6 border-t border-gray-200 space-y-3">
                            <Label forInput="slack_webhook_url" value="Slack alerts" />
                            <p className="text-sm text-gray-500">
                                Down and recovered alerts for this organization's monitors are also
                                posted to a Slack channel through an incoming webhook.{" "}
                                {!organization.has_slack_webhook
                                    ? "Paste the webhook URL to turn this on."
                                    : organization.slack_webhook_unreadable
                                      ? "The saved webhook can't be read any more. Paste it again, or turn Slack alerts off."
                                      : "A webhook is set. Paste a new URL to replace it."}
                            </p>
                            <Input
                                name="slack_webhook_url"
                                type="url"
                                value={form.slack_webhook_url}
                                placeholder="https://hooks.slack.com/services/..."
                                autoComplete="off"
                                handleChange={handleChange}
                            />
                            {organization.has_slack_webhook && (
                                <label className="flex items-center gap-2 text-sm text-gray-700">
                                    <Checkbox
                                        name="remove_slack_webhook"
                                        checked={form.remove_slack_webhook}
                                        handleChange={handleChange}
                                    />
                                    Turn off Slack alerts
                                </label>
                            )}
                        </div>
                        <div className="pt-6 border-t border-gray-200">
                            <Button>Save</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Authenticated>
    );
}
