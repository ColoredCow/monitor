import React from "react";

export default function PageHeader({ children }) {
    return (
        <header className="bg-white/50 border-b border-gray-100">
            <div className="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                {children}
            </div>
        </header>
    );
}
