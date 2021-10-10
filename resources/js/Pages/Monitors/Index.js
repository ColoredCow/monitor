import React from 'react';
import Authenticated from '@/Layouts/Authenticated';
import { Link, usePage } from '@inertiajs/inertia-react';
import { Head } from '@inertiajs/inertia-react';
import { BadgeCheckIcon, ExclamationCircleIcon, ExternalLinkIcon, XCircleIcon } from '@heroicons/react/solid';
import { ClockIcon } from '@heroicons/react/outline';

export default function Dashboard(props) {

    const { monitors } = usePage().props;

    return (
        <Authenticated
            auth={props.auth}
            errors={props.errors}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Monitors</h2>}
        >
            <Head title="Monitors" />

            <div className="py-12">
                <div className="flex flex-wrap max-w-7xl mx-auto sm:px-6 lg:px-8">
                        {monitors.map(monitor => (
                            <div className="w-full md:w-1/2 lg:w-1/3">
                                <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-3 mx-3">
                                    <div className="p-6 bg-white border-b border-gray-200">
                                        <div className="flex items-center mb-2">
                                            <div>{monitor.raw_url}</div>
                                            <a href={monitor.raw_url} target="_blank">
                                                <ExternalLinkIcon className="h-4 w-4 ml-1 text-gray-400"/>
                                            </a>
                                        </div>
                                        <div className="flex">
                                            <div className="flex items-center">
                                                {monitor.uptime_status == 'up' &&
                                                    <span className="py-0.5 pl-1 pr-2 bg-green-200 rounded-full shadow flex items-center">
                                                        <BadgeCheckIcon className="h-5 w-5 mr-1 text-green-500"/>
                                                        <span className="text-green-500 font-bold text-xs">UP</span>
                                                    </span>
                                                }
                                                {monitor.uptime_status == 'down' &&
                                                    <span className="py-0.5 pl-1 pr-2 bg-red-200 rounded-full shadow flex items-center">
                                                        <XCircleIcon className="h-5 w-5 mr-1 text-red-500"/>
                                                        <span className="text-red-500 font-bold text-xs">DOWN</span>
                                                    </span>
                                                }
                                                {monitor.uptime_status == 'not yet checked' &&
                                                    <span className="py-0.5 pl-1 pr-2 bg-blue-200 rounded-full shadow flex items-center">
                                                        <ExclamationCircleIcon className="h-5 w-5 mr-1 text-blue-500"/>
                                                        <span className="text-blue-500 font-bold text-xs">PENDING</span>
                                                    </span>
                                                }
                                            </div>
                                            <div className="flex items-center ml-2">
                                                {monitor.uptime_check_interval_in_minutes &&
                                                    <span className="py-0.5 pl-1 pr-2 bg-gray-200 rounded-full shadow flex items-center">
                                                        <ClockIcon className="h-5 w-5 mr-1 text-gray-500"/>
                                                        <span className="text-gray-500 font-bold text-xs">{monitor.uptime_check_interval_in_minutes}m</span>
                                                    </span>
                                                }
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))}
                </div>
            </div>
        </Authenticated>
    );
}
