import React from "react";

export default function Button({
    type = "submit",
    className = "",
    processing,
    children,
}) {
    return (
        <button
            type={type}
            className={
                `premium-button inline-flex items-center px-6 py-2.5 bg-linear-to-r from-purple-600 to-indigo-600 border border-transparent rounded-xl font-bold text-xs text-white uppercase tracking-widest hover:from-purple-500 hover:to-indigo-500 active:scale-95 transition-all duration-200 ${
                    processing && "opacity-50 cursor-not-allowed"
                } ` + className
            }
            disabled={processing}
        >
            {children}
        </button>
    );
}
