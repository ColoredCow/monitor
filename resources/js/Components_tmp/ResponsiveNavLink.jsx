import React from 'react';
import { Link } from '@inertiajs/react';

export default function ResponsiveNavLink({ method = 'get', as = 'a', href, active = false, children }) {
    return (
        <Link
            method={method}
            as={as}
            href={href}
            className={`w-full flex items-center pl-4 pr-4 py-2.5 border-l-4 ${
                active
                    ? 'border-purple-600 text-purple-700 bg-purple-50 font-semibold focus:outline-none'
                    : 'border-transparent text-gray-600 hover:text-gray-900 hover:bg-gray-50 hover:border-gray-300 font-medium'
            } text-sm focus:outline-none transition-colors duration-150`}
        >
            {children}
        </Link>
    );
}
