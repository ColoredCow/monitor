import React from 'react';

export default function Badge({ text, icon = '', color = 'gray' }) {

    let bgClass = `bg-${color}-200`;
    let textClass = `text-${color}-500`;

    return (
        <span className={`py-0.5 pl-1 pr-2 rounded-full shadow flex items-center ${bgClass}`}>
            {icon}
            <span className={`font-bold text-xs ${textClass}`}>{text}</span>
        </span>
    );
}
