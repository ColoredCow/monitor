import React from 'react';
import { Link } from '@inertiajs/react';

export default function NavLink({ href, active, children }) {
    return (
        <Link
            href={href}
            className={
                active
                    ? 'inline-flex items-center px-3 py-2 border-b-2 border-purple-600 text-sm font-semibold text-gray-900 focus:outline-none transition-colors duration-150'
                    : 'inline-flex items-center px-3 py-2 border-b-2 border-transparent text-sm font-medium text-gray-600 hover:text-gray-900 hover:border-gray-300 focus:outline-none transition-colors duration-150'
            }
        >
            {children}
        </Link>
    );
}
