import React from 'react';

export default function Label({ forInput, className, children }) {
    return (
        <label htmlFor={forInput} className={`block font-medium text-sm text-gray-700 ` + className}>
            {children}
        </label>
    );
}
