import React, { useEffect, useRef } from "react";

export default function Input({
    type = "text",
    name,
    value,
    className = "",
    autoComplete,
    required,
    isFocused,
    placeholder,
    handleChange = () => {},
}) {
    const input = useRef();

    useEffect(() => {
        if (isFocused) {
            input.current?.focus();
        }
    }, [isFocused]);

    const inputProps = {
        type,
        name,
        placeholder,
        className: `w-full px-4 py-2.5 text-sm font-medium text-gray-900 bg-white border border-gray-300 rounded-lg shadow-sm placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200 ${className}`,
        ref: input,
        autoComplete,
        required,
        onChange: (e) => (handleChange ? handleChange(e) : {}),
    };

    // Use controlled or uncontrolled input based on whether value is provided
    if (value !== undefined && value !== null) {
        inputProps.value = value;
    } else {
        inputProps.defaultValue = value;
    }

    return (
        <div className="w-full">
            <input {...inputProps} />
        </div>
    );
}
