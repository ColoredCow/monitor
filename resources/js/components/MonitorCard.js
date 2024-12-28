import React from 'react';
import MonitorUptimeIcon from './MonitorUptimeIcon';
import MonitorCheckIntervalIcon from './MonitorCheckIntervalIcon';
import { Link, router } from '@inertiajs/react';
import { ArrowTopRightOnSquareIcon, PencilIcon, TrashIcon } from '@heroicons/react/24/outline';
import MonitorCertificateIcon from './MonitorCertificateIcon';

export default function MonitorCard ({ monitor }) {

    const handleDelete = (e) => {
        e.preventDefault();
        if (confirm(`Are you sure you want to remove ${monitor.name} (${monitor.raw_url}) from monitoring?`)) {
            router.delete(`/monitors/${monitor.id}`);
        }
    }

    return (
        <div className="w-full md:w-1/2 lg:w-1/3">
            <div className="p-6 bg-white overflow-hidden shadow-sm rounded-lg mb-3 mx-3">
                <div className="flex items-center">{monitor.name}</div>
                <div className="flex items-center mb-2 text-sm">
                    <span className="text-gray-500">{monitor.raw_url}</span>
                    <a className='ml-1 text-gray-400' href={monitor.raw_url} target='_blank'>
                        <ArrowTopRightOnSquareIcon className='h-3.5 w-3.5' />
                    </a>
                </div>
                <div className="flex justify-between">
                    <div className="flex">
                        <div className="flex items-center">
                            <MonitorUptimeIcon monitor={monitor} />
                        </div>
                        <div className="flex items-center ml-2">
                            <MonitorCertificateIcon monitor={monitor} />
                        </div>
                        <div className="flex items-center ml-2">
                            <MonitorCheckIntervalIcon monitor={monitor} />
                        </div>
                    </div>
                    <div className="flex">
                        <Link href={`monitors/${monitor.id}/edit`} className="flex items-center rounded-full px-1.5 py-1.5 text-gray-500 hover:bg-purple-200 hover:text-purple-600">
                            <PencilIcon className='h-4 w-4' />
                        </Link>
                        <div className="flex items-center rounded-full px-1.5 py-1.5 hover:bg-red-200 text-gray-500 hover:text-red-600 cursor-pointer" onClick={handleDelete}>
                            <TrashIcon className='h-4 w-4' />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
