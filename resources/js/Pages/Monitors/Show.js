import React from 'react';
import Authenticated from '@/Layouts/Authenticated';
import { usePage, Head } from '@inertiajs/react';
import { ExternalLinkIcon } from '@heroicons/react/solid';
import MonitorUptimeIcon from '@/components/MonitorUptimeIcon';
import MonitorCheckIntervalIcon from '@/components/MonitorCheckIntervalIcon';
import PageHeader from '@/components/PageHeader';

export default function Show(props) {

    const { monitor } = usePage().props;

    return (
        <Authenticated
            auth={props.auth}
            errors={props.errors}
        >
            <Head title="Monitor" />

            <PageHeader>
                <h2 className="font-bold text-xl text-purple-600 leading-tight uppercase">Monitor</h2>
            </PageHeader>

            <div className="py-12">
                <div className="flex flex-wrap flex-col max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="flex flex-wrap items-center mb-2">
                        <div>{monitor.raw_url}</div>
                        <a href={monitor.raw_url} target="_blank">
                            <ExternalLinkIcon className="h-4 w-4 ml-1 text-gray-400"/>
                        </a>
                    </div>
                    <div className="flex mb-2">
                        <MonitorUptimeIcon monitor={monitor} />
                    </div>
                    <div className="flex">
                        <MonitorCheckIntervalIcon monitor={monitor} />
                    </div>
                </div>
            </div>
        </Authenticated>
    );
}
