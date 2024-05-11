import React from 'react';
import Authenticated from '@/Layouts/Authenticated';
import { Link, usePage, Head } from '@inertiajs/react';
import MonitorCard from '@/components/MonitorCard';
import PageHeader from '@/components/PageHeader';

export default function Dashboard(props) {

    const { monitors } = usePage().props;

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
                <div className="flex flex-wrap max-w-7xl mx-auto sm:px-6 lg:px-8">
                    Name of monitor
                </div>
            </div>
        </Authenticated>
    );
}
