import React from 'react';
import { Link, router } from '@inertiajs/react';
import { PencilIcon, TrashIcon } from '@heroicons/react/24/outline';

export default function UserCard ({ user }) {
    const handleDelete = (e) => {
        e.preventDefault();
        if (confirm(`Are you sure you want to remove the user ${user.name}?`)) {
            router.delete(route('users.destroy', user.id));
        }
    }

    return (
        <div className="w-full md:w-1/2 lg:w-1/3">
            <div className="p-5 bg-white overflow-hidden shadow-sm rounded-lg mb-3 mx-3">
                <div className="flex justify-between">
                    <div className="flex flex-col">
                        <div className="flex items-center">{user.name}</div>
                        <div className="flex items-center mb-2 text-sm">
                            <span className="text-gray-500">{user.email}</span>
                        </div>
                    </div>
                    <div className="flex items-center">
                        <Link href={route('users.edit', user.id)} className="flex items-center rounded-full px-1.5 py-1.5 text-gray-500 hover:bg-purple-200 hover:text-purple-600">
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
