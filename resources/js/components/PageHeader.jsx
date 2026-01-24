import React from "react";

export default function PageHeader({ children }) {
    return (
        <header className="bg-white border-b border-gray-200">
            <div className="max-w-7xl mx-auto py-6 px-6 lg:px-8">
                {children}
            </div>
        </header>
    );
}
