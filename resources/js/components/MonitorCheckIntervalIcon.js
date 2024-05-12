import React from 'react';
import { ClockIcon } from '@heroicons/react/24/outline';
import Badge from './Badge';

export default function MonitorCheckIntervalIcon ({ monitor }) {
    if (monitor.uptime_check_interval_in_minutes) {
        return <Badge icon={<ClockIcon className="h-5 w-5 mr-1 text-gray-500"/>} text={`${monitor.uptime_check_interval_in_minutes}m`} />;
    }
    return <></>;
}
