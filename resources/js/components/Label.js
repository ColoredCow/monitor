import React from "react";

export default function Label({ forInput, className, children, value }) {
    return (
        <label
            htmlFor={forInput}
            className={
                `block font-semibold text-xs text-gray-700 ml-1 mb-1.5 ` +
                className
            }
        >
            {value ? value : children}
        </label>
    );
}
