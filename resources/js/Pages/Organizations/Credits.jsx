import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, Link, router, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Input from "@/Components/Input";
import Label from "@/Components/Label";
import { runwayLabel } from "@/Utils/creditRunway";

const TYPE_LABELS = { grant: "Grant", adjustment: "Adjustment", usage_debit: "Usage" };

export default function Credits() {
    const { auth, organization, dailyBurn, transactions = [], errors = {} } = usePage().props;
    const [form, setForm] = useState({ amount: "", description: "" });

    const handleSubmit = (e) => {
        e.preventDefault();
        router.post(route("organizations.credits.store", organization.id), form, {
            onSuccess: () => setForm({ amount: "", description: "" }),
        });
    };

    return (
        <Authenticated auth={auth}>
            <Head title={`Credits — ${organization.name}`} />
            <PageHeader>
                <div className="flex justify-between items-center">
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                        {organization.name} — Credits
                    </h1>
                    <Link
                        href={route("organizations.index")}
                        className="text-sm text-purple-600 hover:text-purple-800"
                    >
                        Back to organizations
                    </Link>
                </div>
            </PageHeader>
            <div className="max-w-3xl mx-auto py-8 px-6 lg:px-8 space-y-6">
                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <div className="text-sm text-gray-500">Current balance</div>
                    <div className="text-3xl font-bold text-gray-900 mt-1">
                        {(organization.credit_balance ?? 0).toLocaleString()} credits
                    </div>
                    <div className="text-sm text-gray-500 mt-2">
                        {dailyBurn.toLocaleString()} credits/day — lasts{" "}
                        {runwayLabel(organization.credit_balance, dailyBurn)}. Warning level:{" "}
                        {organization.credit_warning_level}.
                    </div>
                </div>

                <form
                    onSubmit={handleSubmit}
                    className="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-4"
                >
                    <h2 className="text-sm font-semibold text-gray-700">Grant or adjust credits</h2>
                    <div>
                        <Label forInput="amount" value="Amount (negative for a correction)" />
                        <Input
                            type="number"
                            name="amount"
                            value={form.amount}
                            className="mt-1 block w-full"
                            handleChange={(e) => setForm({ ...form, amount: e.target.value })}
                        />
                        {errors.amount && (
                            <div className="text-sm text-red-600 mt-1">{errors.amount}</div>
                        )}
                    </div>
                    <div>
                        <Label forInput="description" value="Description (optional)" />
                        <Input
                            type="text"
                            name="description"
                            value={form.description}
                            className="mt-1 block w-full"
                            handleChange={(e) => setForm({ ...form, description: e.target.value })}
                        />
                        {errors.description && (
                            <div className="text-sm text-red-600 mt-1">{errors.description}</div>
                        )}
                    </div>
                    <Button>Apply</Button>
                </form>

                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h2 className="text-sm font-semibold text-gray-700 mb-4">Transactions</h2>
                    {transactions.length === 0 ? (
                        <div className="text-sm text-gray-400">No transactions yet.</div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs text-gray-400 uppercase tracking-wide">
                                    <th className="pb-2">When</th>
                                    <th className="pb-2">Type</th>
                                    <th className="pb-2">Description</th>
                                    <th className="pb-2 text-right">Amount</th>
                                    <th className="pb-2 text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {transactions.map((transaction) => (
                                    <tr key={transaction.id}>
                                        <td className="py-2 text-gray-500">{transaction.created_at}</td>
                                        <td className="py-2 text-gray-700">
                                            {TYPE_LABELS[transaction.type] ?? transaction.type}
                                        </td>
                                        <td className="py-2 text-gray-500">
                                            {transaction.description}
                                            {transaction.created_by && ` — ${transaction.created_by}`}
                                        </td>
                                        <td
                                            className={`py-2 text-right font-medium ${
                                                transaction.amount >= 0 ? "text-green-600" : "text-gray-700"
                                            }`}
                                        >
                                            {transaction.amount >= 0 ? "+" : ""}
                                            {transaction.amount.toLocaleString()}
                                        </td>
                                        <td className="py-2 text-right text-gray-500">
                                            {transaction.balance_after.toLocaleString()}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </Authenticated>
    );
}
