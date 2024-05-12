import React from 'react';

export default function Checkbox({ name, checked, handleChange, className }) {
    return (
        <input
            type="checkbox"
            name={name}
            checked={checked}
            className={`rounded border-gray-300 text-purple-600 shadow-sm focus:border-purple-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50` + className}
            onChange={handleChange}
        />
    );
}
