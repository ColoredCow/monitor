import React from 'react';
import { CheckBadgeIcon, ExclamationCircleIcon, XCircleIcon } from '@heroicons/react/24/solid';
import Badge from './Badge';
import {
    CHECK_STATUS,
    getCheckStatusBadgeColor,
    mapUptimeStatusToCheckStatus,
} from '@/Utils/checkStatusSeverity';

export default function MonitorUptimeIcon ({ monitor }) {
    const checkStatus = mapUptimeStatusToCheckStatus(monitor.uptime_status);

    if (checkStatus === CHECK_STATUS.SUCCESS) {
        return <Badge icon={<CheckBadgeIcon className="h-4 w-4 text-green-600"/>} text="UP" color={getCheckStatusBadgeColor(checkStatus)} />;
    }
    if (checkStatus === CHECK_STATUS.FAILED) {
        return <Badge icon={<XCircleIcon className="h-4 w-4 text-red-600"/>} text="DOWN" color={getCheckStatusBadgeColor(checkStatus)} />;
    }
    if (checkStatus === CHECK_STATUS.UNKNOWN) {
        return <Badge icon={<ExclamationCircleIcon className="h-4 w-4 text-gray-600"/>} text="PENDING" color={getCheckStatusBadgeColor(checkStatus)} />;
    }
    return <></>;
}
