import React from 'react';
import { BadgeCheckIcon, ExclamationCircleIcon, ExternalLinkIcon, XCircleIcon } from '@heroicons/react/solid';
import { ClockIcon } from '@heroicons/react/outline';
import Badge from './Badge';

export default function MonitorCard ({ monitor }) {
    return (
        <div className="w-full md:w-1/2 lg:w-1/3">
            <div className="p-6 bg-white overflow-hidden shadow-sm rounded-lg mb-3 mx-3">
                <div className="flex items-center mb-2">
                    <div>{monitor.raw_url}</div>
                    <a href={monitor.raw_url} target="_blank">
                        <ExternalLinkIcon className="h-4 w-4 ml-1 text-gray-400"/>
                    </a>
                </div>
                <div className="flex">
                    <div className="flex items-center">
                        {monitor.uptime_status == 'up' &&
                            <Badge icon={<BadgeCheckIcon className="h-5 w-5 mr-1 text-green-500"/>} text="UP" color="green" />
                        }
                        {monitor.uptime_status == 'down' &&
                            <Badge icon={<XCircleIcon className="h-5 w-5 mr-1 text-red-500"/>} text="DOWN" color="red" />
                        }
                        {monitor.uptime_status == 'not yet checked' &&
                            <Badge icon={<ExclamationCircleIcon className="h-5 w-5 mr-1 text-blue-500"/>} text="PENDING" color="blue" />
                        }
                    </div>
                    <div className="flex items-center ml-2">
                        {monitor.uptime_check_interval_in_minutes &&
                            <Badge icon={<ClockIcon className="h-5 w-5 mr-1 text-gray-500"/>} text={`${monitor.uptime_check_interval_in_minutes}m`} />
                        }
                    </div>
                </div>
            </div>
        </div>
    );
}
