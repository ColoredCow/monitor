import React, { useEffect, useRef } from "react";

export default function Input({
    type = "text",
    name,
    value,
    className,
    autoComplete,
    required,
    isFocused,
    handleChange = () => {},
}) {
    const input = useRef();

    useEffect(() => {
        if (isFocused) {
            input.current.focus();
        }
    }, []);

    return (
        <div className="flex flex-col items-start w-full">
            <input
                type={type}
                name={name}
                defaultValue={value}
                className={`premium-input py-2.5 px-4 text-sm ` + className}
                ref={input}
                autoComplete={autoComplete}
                required={required}
                onChange={(e) => (handleChange ? handleChange(e) : {})}
            />
        </div>
    );
}
