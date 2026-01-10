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
                `premium-input py-2.5 px-4 text-sm w-full appearance-none bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20fill%3D%22none%22%20viewBox%3D%220%200%2020%2020%22%3E%3Cpath%20stroke%3D%22%236b7280%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%20stroke-width%3D%221.5%22%20d%3D%22m6%208%204%204%204-4%22%2F%3E%3C%2Fsvg%3E')] bg-size-[1.25rem_1.25rem] bg-position-[right_0.5rem_center] bg-no-repeat ` +
                className
            }
            onChange={handleChange}
        >
            {children}
        </select>
    );
}
