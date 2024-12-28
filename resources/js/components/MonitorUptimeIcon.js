import React from 'react';
import { CheckBadgeIcon, ExclamationCircleIcon, XCircleIcon } from '@heroicons/react/24/solid';
import Badge from './Badge';

export default function MonitorUptimeIcon ({ monitor }) {
    if (monitor.uptime_status == 'up') {
        return <Badge icon={<CheckBadgeIcon className="h-5 w-5 mr-1 text-green-500"/>} text="Uptime" color="green" title="OK" />;
    }
    if (monitor.uptime_status == 'down') {
        return <Badge icon={<XCircleIcon className="h-5 w-5 mr-1 text-red-500"/>} text="Uptime" color="red" title="Down" />;
    }
    if (monitor.uptime_status == 'not yet checked') {
        return <Badge icon={<ExclamationCircleIcon className="h-5 w-5 mr-1 text-blue-500"/>} text="Uptime" color="blue" title="Pending" />;
    }
    return <></>;
}
