import React from 'react';

export default function Badge({ text, icon = null, color = 'gray' }) {
    const colorMap = {
        green: 'bg-green-50 text-green-700 border-green-200',
        red: 'bg-red-50 text-red-700 border-red-200',
        blue: 'bg-blue-50 text-blue-700 border-blue-200',
        purple: 'bg-purple-50 text-purple-700 border-purple-200',
        yellow: 'bg-yellow-50 text-yellow-700 border-yellow-200',
        pink: 'bg-pink-50 text-pink-700 border-pink-200',
        gray: 'bg-gray-50 text-gray-700 border-gray-200',
    };

    const colorClasses = colorMap[color] || colorMap.gray;

    return (
        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-xs font-medium ${colorClasses}`}>
            {icon && <span className="flex-shrink-0 flex items-center">{icon}</span>}
            <span>{text}</span>
        </span>
    );
}
