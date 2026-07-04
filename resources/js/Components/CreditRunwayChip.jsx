import React from "react";
import { Link } from "@inertiajs/react";
import { runwayLabel } from "@/Utils/creditRunway";

const TONES = {
    danger: "bg-red-50 text-red-700 hover:bg-red-100",
    warning: "bg-amber-50 text-amber-700 hover:bg-amber-100",
    neutral: "bg-gray-100 text-gray-600 hover:bg-gray-200",
};

export default function CreditRunwayChip({ credits }) {
    if (!credits) return null;

    const tone =
        credits.balance <= 0 ||
        credits.warningLevel === "exhausted" ||
        credits.warningLevel === "critical"
            ? "danger"
            : credits.warningLevel === "low"
              ? "warning"
              : "neutral";

    return (
        <Link
            href={route("credits.index")}
            title={`${credits.balance.toLocaleString()} credits · ${credits.dailyBurn.toLocaleString()} credits/day`}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-colors ${TONES[tone]}`}
        >
            <span
                className={`h-1.5 w-1.5 rounded-full ${
                    tone === "danger" ? "bg-red-500" : tone === "warning" ? "bg-amber-500" : "bg-green-500"
                }`}
            />
            {credits.balance <= 0 ? "Out of credits" : runwayLabel(credits.balance, credits.dailyBurn)}
        </Link>
    );
}
