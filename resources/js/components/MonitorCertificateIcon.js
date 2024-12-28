import React from 'react';
import { ExclamationCircleIcon, ShieldCheckIcon, ShieldExclamationIcon } from '@heroicons/react/24/solid';
import Badge from './Badge';

export default function MonitorCertificateIcon ({ monitor }) {
    if (monitor.uptime_status == 'up') {
        return <Badge icon={<ShieldCheckIcon className="h-5 w-5 mr-1 text-green-500"/>} text="SSL" color="green" title="OK" />;
    }
    if (monitor.uptime_status == 'down') {
        return <Badge icon={<ShieldExclamationIcon className="h-5 w-5 mr-1 text-red-500"/>} text="SSL" color="red" title="Invalid" />;
    }
    if (monitor.uptime_status == 'not yet checked') {
        return <Badge icon={<ExclamationCircleIcon className="h-5 w-5 mr-1 text-blue-500"/>} text="SSL" color="blue" title="Pending" />;
    }
    return <></>;
}
