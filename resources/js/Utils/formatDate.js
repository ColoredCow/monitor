// All formatting is done in UTC by design. The backend ships ISO strings that
// have already been shifted into the configured server timezone, so we must NOT
// re-shift them into the browser timezone (Global Constraint).

function toUTCDate(iso) {
    if (!iso) {
        return null;
    }

    // Accept 'YYYY-MM-DD' and 'YYYY-MM-DD HH:mm:ss' (the checked_at payload shape).
    const normalized = String(iso).trim().replace(" ", "T");
    const [datePart, timePart = "00:00:00"] = normalized.split("T");
    const [year, month, day] = datePart.split("-").map(Number);
    const [hour = 0, minute = 0, second = 0] = timePart
        .split(":")
        .map(Number);

    if (!year || !month || !day) {
        return null;
    }

    // Reject if any component is NaN (from malformed input).
    if (Number.isNaN(year) || Number.isNaN(month) || Number.isNaN(day) ||
        Number.isNaN(hour) || Number.isNaN(minute) || Number.isNaN(second)) {
        return null;
    }

    return new Date(Date.UTC(year, month - 1, day, hour, minute, second));
}

const DATE_FORMATTER = new Intl.DateTimeFormat("en-GB", {
    timeZone: "UTC",
    day: "2-digit",
    month: "short",
    year: "numeric",
});

const TIME_FORMATTER = new Intl.DateTimeFormat("en-GB", {
    timeZone: "UTC",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
});

export function formatDateUTC(iso) {
    const date = toUTCDate(iso);

    if (!date) {
        return "";
    }

    // en-GB '2-digit/short/numeric' yields '27 Mar 2026'.
    return DATE_FORMATTER.format(date);
}

export function formatDateTimeUTC(iso) {
    const date = toUTCDate(iso);

    if (!date) {
        return "";
    }

    return `${DATE_FORMATTER.format(date)}, ${TIME_FORMATTER.format(date)}`;
}

export function formatRelative(iso, nowMs) {
    const date = toUTCDate(iso);

    if (!date) {
        return "";
    }

    const deltaMs = Number(nowMs) - date.getTime();
    const deltaSeconds = Math.floor(deltaMs / 1000);

    if (deltaSeconds < 60) {
        return "just now";
    }

    const deltaMinutes = Math.floor(deltaSeconds / 60);
    if (deltaMinutes < 60) {
        return `${deltaMinutes}m ago`;
    }

    const deltaHours = Math.floor(deltaMinutes / 60);
    if (deltaHours < 24) {
        return `${deltaHours}h ago`;
    }

    const deltaDays = Math.floor(deltaHours / 24);
    return `${deltaDays}d ago`;
}
