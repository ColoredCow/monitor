import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, router, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";
import Checkbox from "@/Components/Checkbox";
import ValidationErrors from "@/Components/ValidationErrors";

// One alert channel's webhook. The page never receives the URL itself, only
// whether one is set and whether it can still be read.
function WebhookSetting({ service, label, intro, unsetText, offAction, offLabel, placeholder, organization, form, handleChange }) {
    const field = `${service}_webhook_url`;
    const remove = `remove_${service}_webhook`;
    const isSet = organization[`has_${service}_webhook`];
    const unreadable = organization[`${service}_webhook_unreadable`];

    return (
        <div className="pt-6 border-t border-gray-200 space-y-3">
            <Label forInput={field} value={label} />
            <p className="text-sm text-gray-500">
                {intro}{" "}
                {!isSet
                    ? unsetText
                    : unreadable
                      ? `The saved webhook can't be read any more. Paste it again, or ${offAction}.`
                      : "A webhook is set. Paste a new URL to replace it."}
            </p>
            <Input
                name={field}
                type="url"
                value={form[field]}
                placeholder={placeholder}
                autoComplete="off"
                handleChange={handleChange}
            />
            {isSet && (
                <label className="flex items-center gap-2 text-sm text-gray-700">
                    <Checkbox name={remove} checked={form[remove]} handleChange={handleChange} />
                    {offLabel}
                </label>
            )}
        </div>
    );
}

export default function Edit() {
    const { auth, organization, errors } = usePage().props;
    const [form, setForm] = useState({
        name: organization.name,
        google_chat_webhook_url: "",
        remove_google_chat_webhook: false,
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

    const webhookProps = { organization, form, handleChange };

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
                        <WebhookSetting
                            {...webhookProps}
                            service="google_chat"
                            label="Google Chat alerts"
                            intro="Down and recovered alerts for this organization's monitors go to this Google Chat space."
                            unsetText="Until you set one, they go to the default space. Paste a space's webhook URL to use it instead."
                            offAction="go back to the default space"
                            offLabel="Go back to the default space"
                            placeholder="https://chat.googleapis.com/v1/spaces/..."
                        />
                        <WebhookSetting
                            {...webhookProps}
                            service="slack"
                            label="Slack alerts"
                            intro="Down and recovered alerts for this organization's monitors are also posted to a Slack channel through an incoming webhook."
                            unsetText="Paste the webhook URL to turn this on."
                            offAction="turn Slack alerts off"
                            offLabel="Turn off Slack alerts"
                            placeholder="https://hooks.slack.com/services/..."
                        />
                        <div className="pt-6 border-t border-gray-200">
                            <Button>Save</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Authenticated>
    );
}
