import React from "react";

export default function Select({
    name,
    value,
    handleChange,
    children,
    className = "",
    required = false,
    id,
}) {
    return (
        <select
            id={id || name}
            name={name}
            value={value}
            required={required}
            className={
                `w-full px-4 py-2.5 text-sm font-medium text-gray-900 bg-white border border-gray-300 rounded-lg shadow-sm appearance-none bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20fill%3D%22none%22%20viewBox%3D%220%200%2020%2020%22%3E%3Cpath%20stroke%3D%22%236b7280%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%20stroke-width%3D%222%22%20d%3D%22m6%208%204%204%204-4%22%2F%3E%3C%2Fsvg%3E')] bg-[length:1.25rem_1.25rem] bg-[right_0.75rem_center] bg-no-repeat cursor-pointer focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200 ` +
                className
            }
            onChange={handleChange}
        >
            {children}
        </select>
    );
}
