import React from 'react';
import Authenticated from '@/Layouts/Authenticated';
import { Link, usePage } from '@inertiajs/inertia-react';
import { Head } from '@inertiajs/inertia-react';
import MonitorCard from '@/components/MonitorCard';

export default function Dashboard(props) {

    const { monitors } = usePage().props;

    return (
        <Authenticated
            auth={props.auth}
            errors={props.errors}
        >
            <header className="bg-white shadow">
                <div className="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    <h2 className="font-bold text-xl text-purple-600 leading-tight uppercase">Monitors</h2>
                </div>
            </header>

            <Head title="Monitors" />

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
