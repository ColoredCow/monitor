import React from 'react';
import Authenticated from '@/Layouts/Authenticated';
import { Head, Link, usePage } from '@inertiajs/react';
import UserCard from '@/Components/UserCard';
import PageHeader from '@/Components/PageHeader';
import Button from '@/Components/Button';
import { PlusIcon } from '@heroicons/react/24/solid';

export default function Index(props) {
    const { users } = usePage().props;

    return (
        <Authenticated
            auth={props.auth}
            errors={props.errors}
        >
            <Head title="Users" />

            <PageHeader>
                <div className='flex justify-between items-center'>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Users</h1>
                        <p className="text-sm text-gray-500 mt-1">Manage user accounts and access</p>
                    </div>
                    <Link href={route('users.create')}>
                        <Button>
                            <PlusIcon className="h-4 w-4"/>
                            <span>Create</span>
                        </Button>
                    </Link>
                </div>
            </PageHeader>

            <div className="max-w-7xl mx-auto py-8 px-6 lg:px-8">
                <div className="flex flex-wrap -mx-3">
                    {users.map((user, index) => (
                        <UserCard user={user} key={index} />
                    ))}
                </div>
            </div>
        </Authenticated>
    );
}
