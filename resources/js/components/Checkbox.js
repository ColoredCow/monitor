import React from "react";

export default function Checkbox({ name, checked, handleChange, className }) {
    return (
        <input
            type="checkbox"
            name={name}
            checked={checked}
            className={
                `rounded-lg border-gray-300 text-purple-600 shadow-sm focus:ring-4 focus:ring-purple-500/10 transition-all duration-200 ` +
                className
            }
            onChange={handleChange}
        />
    );
}
