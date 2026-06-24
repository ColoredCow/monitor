export const CHECK_STATUS = Object.freeze({
    SUCCESS: "success",
    WARNING: "warning",
    FAILED: "failed",
    UNKNOWN: "unknown",
});

const CHECK_STATUS_META = Object.freeze({
    [CHECK_STATUS.SUCCESS]: {
        severity: 0,
        badgeColor: "green",
        heatmapClass: "bg-green-500",
        label: "Healthy",
    },
    [CHECK_STATUS.WARNING]: {
        severity: 1,
        badgeColor: "yellow",
        heatmapClass: "bg-yellow-400",
        label: "Warning",
    },
    [CHECK_STATUS.FAILED]: {
        severity: 2,
        badgeColor: "red",
        heatmapClass: "bg-red-500",
        label: "Failed",
    },
    [CHECK_STATUS.UNKNOWN]: {
        severity: 3,
        badgeColor: "gray",
        heatmapClass: "bg-gray-300",
        label: "Unknown",
    },
});

export function normalizeCheckStatus(status) {
    if (Object.values(CHECK_STATUS).includes(status)) {
        return status;
    }

    return CHECK_STATUS.UNKNOWN;
}

export function getCheckStatusMeta(status) {
    const normalizedStatus = normalizeCheckStatus(status);

    return CHECK_STATUS_META[normalizedStatus];
}

export function getCheckStatusBadgeColor(status) {
    return getCheckStatusMeta(status).badgeColor;
}

export function mapUptimeStatusToCheckStatus(uptimeStatus) {
    switch (uptimeStatus) {
        case "up":
            return CHECK_STATUS.SUCCESS;
        case "down":
            return CHECK_STATUS.FAILED;
        case "not yet checked":
            return CHECK_STATUS.UNKNOWN;
        default:
            return CHECK_STATUS.UNKNOWN;
    }
}

export const CHECK_TYPE_STATUSES = Object.freeze({
    uptime: [CHECK_STATUS.SUCCESS, CHECK_STATUS.FAILED, CHECK_STATUS.UNKNOWN],
    domain: [
        CHECK_STATUS.SUCCESS,
        CHECK_STATUS.WARNING,
        CHECK_STATUS.FAILED,
        CHECK_STATUS.UNKNOWN,
    ],
    certificate: [
        CHECK_STATUS.SUCCESS,
        CHECK_STATUS.FAILED,
        CHECK_STATUS.UNKNOWN,
    ],
});

const ALL_CHECK_STATUSES = [
    CHECK_STATUS.SUCCESS,
    CHECK_STATUS.WARNING,
    CHECK_STATUS.FAILED,
    CHECK_STATUS.UNKNOWN,
];

export function statusesForCheckType(checkType) {
    const statuses = CHECK_TYPE_STATUSES[checkType] || ALL_CHECK_STATUSES;

    return [...statuses];
}
