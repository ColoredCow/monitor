import React from 'react';
import MonitorUptimeIcon from './MonitorUptimeIcon';
import MonitorCheckIntervalIcon from './MonitorCheckIntervalIcon';
import { Link } from '@inertiajs/react';

export default function MonitorCard ({ monitor }) {
    return (
        <Link className="w-full md:w-1/2 lg:w-1/3" href={`/monitors/${monitor.id}`}>
            <div className="p-6 bg-white overflow-hidden shadow-sm rounded-lg mb-3 mx-3">
                <div className="flex items-center mb-2">
                    <div>{monitor.raw_url}</div>
                </div>
                <div className="flex">
                    <div className="flex items-center">
                        <MonitorUptimeIcon monitor={monitor} />
                    </div>
                    <div className="flex items-center ml-2">
                        <MonitorCheckIntervalIcon monitor={monitor} />
                    </div>
                </div>
            </div>
        </Link>
    );
}
