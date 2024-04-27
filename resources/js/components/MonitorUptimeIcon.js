import React from 'react';
import { BadgeCheckIcon, ExclamationCircleIcon, XCircleIcon } from '@heroicons/react/solid';
import Badge from './Badge';

export default function MonitorUptimeIcon ({ monitor }) {
    if (monitor.uptime_status == 'up') {
        return <Badge icon={<BadgeCheckIcon className="h-5 w-5 mr-1 text-green-500"/>} text="UP" color="green" />;
    }
    if (monitor.uptime_status == 'down') {
        return <Badge icon={<XCircleIcon className="h-5 w-5 mr-1 text-red-500"/>} text="DOWN" color="red" />;
    }
    if (monitor.uptime_status == 'not yet checked') {
        return <Badge icon={<ExclamationCircleIcon className="h-5 w-5 mr-1 text-blue-500"/>} text="PENDING" color="blue" />;
    }
    return <></>;
}
