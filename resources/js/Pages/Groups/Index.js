import React from 'react';
import Authenticated from '@/Layouts/Authenticated';
import { Head, Link, usePage } from '@inertiajs/react';
import GroupCard from '@/components/GroupCard';
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
            <Head title="Groups" />

            <PageHeader>
                <div className='flex justify-between items-center'>
                    <h2 className="font-bold text-xl text-purple-600 leading-tight uppercase">Groups</h2>
                    <Link href='groups/create'>
                        <Button>
                            <PlusIcon className="h-4 w-4 mr-1"/>
                            <span>Create</span>
                        </Button>
                    </Link>
                </div>
            </PageHeader>

            <div className="py-12">
                <div className="flex flex-wrap max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {groups.map((group, index) => (
                        <GroupCard group={group} key={index} />
                    ))}
                </div>
            </div>
        </Authenticated>
    );
}
