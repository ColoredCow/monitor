import React from "react";

export default function Checkbox({ name, checked, handleChange, className = "" }) {
    return (
        <input
            type="checkbox"
            name={name}
            checked={checked}
            className={`w-4 h-4 rounded border-gray-300 text-purple-600 shadow-sm focus:ring-2 focus:ring-purple-500 focus:ring-offset-0 cursor-pointer transition-all duration-200 ${className}`}
            onChange={handleChange}
        />
    );
}
