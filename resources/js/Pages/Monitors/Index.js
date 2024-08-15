import React from 'react';
import Authenticated from '@/Layouts/Authenticated';
import { Head, Link, usePage } from '@inertiajs/react';
import MonitorCard from '@/components/MonitorCard';
import PageHeader from '@/components/PageHeader';
import Button from '@/Components/Button';
import { PlusIcon } from '@heroicons/react/24/solid';

export default function Index(props) {

    const { monitors } = usePage().props;
    
    return (
        <Authenticated
            auth={props.auth}
            errors={props.errors}
        >
            <Head title="Monitors" />

            <PageHeader>
                <div className='flex justify-between'>
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
                <div className="flex flex-wrap max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {monitors.map((monitor, index) => (
                        <MonitorCard monitor={monitor} key={index} />
                    ))}
                </div>
            </div>
        </Authenticated>
    );
}
