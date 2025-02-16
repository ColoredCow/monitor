import React from 'react';
import Authenticated from '@/Layouts/Authenticated';
import { Head, Link, usePage } from '@inertiajs/react';
import MonitorCard from '@/components/MonitorCard';
import PageHeader from '@/components/PageHeader';
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
                    <h2 className="font-bold text-xl text-purple-600 leading-tight uppercase">Monitors</h2>
                    <Link href='monitors/create'>
                        <Button>
                            <PlusIcon className="h-4 w-4 mr-1"/>
                            <span>Create</span>
                        </Button>
                    </Link>
                </div>
            </PageHeader>

            <div className="py-12">
                {groups.map((group, groupIndex) => (
                    <div className="flex flex-col max-w-7xl mx-auto sm:px-6 lg:px-8 mb-6" key={groupIndex}>
                        <h3 className="pl-3 mb-3 text-xl">{group.name}</h3>
                        <div className="flex flex-wrap">
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
