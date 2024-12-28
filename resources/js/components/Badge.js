import React from 'react';

export default function Badge({ text, icon = '', color = 'gray', ...rest }) {

    let bgClass = `bg-${color}-200`;
    let textClass = `text-${color}-500`;

    return (
        <span className={`py-0.5 px-1 rounded-full shadow flex items-center cursor-default ${bgClass}`} {...rest}>
            {icon}
            {text && <span className={`font-bold text-xs pr-1 ${textClass}`}>{text}</span>}
        </span>
    );
}
