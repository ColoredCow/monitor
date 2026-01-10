import React from 'react';
import { ClockIcon } from '@heroicons/react/24/outline';
import Badge from './Badge';

export default function MonitorCheckIntervalIcon ({ monitor }) {
    if (monitor.uptime_check_interval_in_minutes) {
        return <Badge icon={<ClockIcon className="h-4 w-4 text-gray-600"/>} text={`${monitor.uptime_check_interval_in_minutes}m`} color="gray" />;
    }
    return <></>;
}
