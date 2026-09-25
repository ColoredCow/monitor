import React from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import { runwayLabel } from "@/Utils/creditRunway";

const TYPE_LABELS = { grant: "Grant", adjustment: "Adjustment", usage_debit: "Usage" };

export default function Index() {
    const {
        auth,
        balance,
        warningLevel,
        dailyBurn,
        usageByDay = [],
        topMonitors = [],
        transactions = [],
    } = usePage().props;

    const maxDay = Math.max(1, ...usageByDay.map((d) => d.total));

    return (
        <Authenticated auth={auth}>
            <Head title="Credits" />
            <PageHeader>
                <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Credits</h1>
            </PageHeader>
            <div className="max-w-5xl mx-auto py-8 px-6 lg:px-8 space-y-6">
                {balance <= 0 && (
                    <div className="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 font-medium">
                        Monitoring paused — out of credits. Contact your service administrator to top up.
                    </div>
                )}

                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <div className="text-sm text-gray-500">Current balance</div>
                    <div className="text-3xl font-bold text-gray-900 mt-1">
                        {balance.toLocaleString()} credits
                    </div>
                    <div className="text-sm text-gray-500 mt-2">
                        Burning {dailyBurn.toLocaleString()} credits/day at the current configuration —
                        credits last <span className="font-medium text-gray-700">{runwayLabel(balance, dailyBurn)}</span>.
                    </div>
                    {warningLevel !== "none" && balance > 0 && (
                        <div className="text-xs font-medium text-amber-600 mt-2 uppercase tracking-wide">
                            Warning level: {warningLevel}
                        </div>
                    )}
                </div>

                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h2 className="text-sm font-semibold text-gray-700 mb-4">Usage — last 30 days</h2>
                    {usageByDay.length === 0 ? (
                        <div className="text-sm text-gray-400">No usage recorded yet.</div>
                    ) : (
                        <div className="flex items-end gap-1 h-32">
                            {usageByDay.map((day) => (
                                <div
                                    key={day.date}
                                    title={`${day.date}: ${day.total.toLocaleString()} credits`}
                                    className="flex-1 bg-purple-200 hover:bg-purple-300 rounded-t transition-colors"
                                    style={{ height: `${Math.max(4, (day.total / maxDay) * 100)}%` }}
                                />
                            ))}
                        </div>
                    )}
                </div>

                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h2 className="text-sm font-semibold text-gray-700 mb-4">Top monitors — last 30 days</h2>
                    {topMonitors.length === 0 ? (
                        <div className="text-sm text-gray-400">No usage recorded yet.</div>
                    ) : (
                        <ul className="space-y-2">
                            {topMonitors.map((monitor) => (
                                <li key={monitor.monitor_id} className="flex justify-between text-sm">
                                    <span className="text-gray-700">{monitor.name}</span>
                                    <span className="text-gray-500">{monitor.credits.toLocaleString()} credits</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

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
