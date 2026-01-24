import React from 'react';
import Authenticated from '@/Layouts/Authenticated';
import { Head, Link, usePage } from '@inertiajs/react';
import MonitorCard from '@/Components/MonitorCard';
import PageHeader from '@/Components/PageHeader';
import Button from '@/Components/Button';
import { PlusIcon } from '@heroicons/react/24/solid';

export default function Index(props) {

    const { groups } = usePage().props;

    return (
        <Authenticated
            auth={props.auth}
            errors={props.errors}
        >
            <Head title="Monitors" />

            <PageHeader>
                <div className='flex justify-between items-center'>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Monitors</h1>
                        <p className="text-sm text-gray-500 mt-1">Manage and monitor your endpoints</p>
                    </div>
                    <Link href={route('monitors.create')}>
                        <Button>
                            <PlusIcon className="h-4 w-4"/>
                            <span>Create</span>
                        </Button>
                    </Link>
                </div>
            </PageHeader>

            <div className="max-w-7xl mx-auto py-8 px-6 lg:px-8">
                {groups.map((group, groupIndex) => (
                    <div className="mb-10 last:mb-0" key={groupIndex}>
                        <h2 className="text-lg font-semibold text-gray-900 mb-4 px-1">{group.name}</h2>
                        <div className="flex flex-wrap -mx-3">
                            {group.monitors.map((monitor, monitorIndex) => (
                                <MonitorCard monitor={monitor} key={monitorIndex} />
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </Authenticated>
    );
}
