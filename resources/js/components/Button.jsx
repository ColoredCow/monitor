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
                `inline-flex items-center justify-center gap-2 px-6 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 border border-transparent rounded-lg font-semibold text-sm text-white shadow-sm hover:from-purple-700 hover:to-indigo-700 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 active:scale-[0.98] transition-all duration-200 ${
                    processing && "opacity-50 cursor-not-allowed"
                } ${className}`
            }
            disabled={processing}
        >
            {children}
        </button>
    );
}
