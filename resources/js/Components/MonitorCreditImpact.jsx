import React from "react";
import { dailyBurnForConfig, runwayLabel } from "@/Utils/creditRunway";

export default function MonitorCreditImpact({ credits, burnBefore = 0, form, certificateEnabled = false }) {
    if (!credits) return null;

    const burnAfter = dailyBurnForConfig({
        intervalMinutes: form.uptimeCheckInterval,
        uptimeEnabled: form.monitorUptime,
        certificateEnabled,
        domainEnabled: form.monitorDomain,
    });

    const orgBurnAfter = Math.max(0, credits.dailyBurn - burnBefore + burnAfter);

    return (
        <div className="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
            This monitor will use{" "}
            <span className="font-medium text-gray-800">{burnAfter.toLocaleString()} credits/day</span>
            {burnBefore !== burnAfter && ` (currently ${burnBefore.toLocaleString()})`}. Organization total:{" "}
            {orgBurnAfter.toLocaleString()} credits/day — credits last{" "}
            <span className="font-medium text-gray-800">{runwayLabel(credits.balance, orgBurnAfter)}</span>.
        </div>
    );
}
