import React from "react";

export default function Label({ forInput, className = "", children, value }) {
    return (
        <label
            htmlFor={forInput}
            className={`block text-sm font-semibold text-gray-700 mb-2 ${className}`}
        >
            {value ? value : children}
        </label>
    );
}
