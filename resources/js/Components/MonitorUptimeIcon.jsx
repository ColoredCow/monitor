import React from 'react';
import { CheckBadgeIcon, ExclamationCircleIcon, XCircleIcon } from '@heroicons/react/24/solid';
import Badge from './Badge';

export default function MonitorUptimeIcon ({ monitor }) {
    if (monitor.uptime_status == 'up') {
        return <Badge icon={<CheckBadgeIcon className="h-4 w-4 text-green-600"/>} text="UP" color="green" />;
    }
    if (monitor.uptime_status == 'down') {
        return <Badge icon={<XCircleIcon className="h-4 w-4 text-red-600"/>} text="DOWN" color="red" />;
    }
    if (monitor.uptime_status == 'not yet checked') {
        return <Badge icon={<ExclamationCircleIcon className="h-4 w-4 text-blue-600"/>} text="PENDING" color="blue" />;
    }
    return <></>;
}
