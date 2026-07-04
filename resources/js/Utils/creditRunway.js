// Mirrors App\Services\CreditRunwayService — keep the burn math in sync.
export function dailyBurnForConfig({
    intervalMinutes,
    uptimeEnabled,
    certificateEnabled = false,
    domainEnabled,
}) {
    let burn = 0;

    if (uptimeEnabled) {
        const interval = Math.max(1, parseInt(intervalMinutes, 10) || 1);
        burn += Math.floor(1440 / interval);
    }

    if (certificateEnabled) burn += 1;
    if (domainEnabled) burn += 1;

    return burn;
}

export function runwayLabel(balance, dailyBurn) {
    if (dailyBurn <= 0) return "credits aren't being consumed";
    if (balance <= 0) return "out of credits";

    const days = balance / dailyBurn;

    if (days >= 365) return "over a year";
    if (days >= 60) return `~${Math.round(days / 30)} months`;
    if (days >= 14) return `~${Math.round(days / 7)} weeks`;
    if (days >= 1.5) return `~${Math.round(days)} days`;
    if (days >= 1) return "~1 day";

    const hours = days * 24;
    if (hours >= 1) return `~${Math.round(hours)} hours`;

    return "less than an hour";
}
