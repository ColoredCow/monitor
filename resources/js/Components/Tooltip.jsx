import React, { useId, useState } from "react";

// Reusable hover + keyboard-focus tooltip.
// - role="tooltip" with aria-describedby wired to the trigger for screen readers.
// - Shows on mouse hover AND keyboard focus (focus-visible reachable trigger).
// - Positioned above the trigger, centered; pointer-events disabled so it never
//   steals hover. Motion is gated with motion-reduce:* per Global Constraints.
export default function Tooltip({ content, children, className = "" }) {
    const tooltipId = useId();
    const [open, setOpen] = useState(false);

    const show = () => setOpen(true);
    const hide = () => setOpen(false);

    if (content === null || content === undefined || content === "") {
        return children;
    }

    return (
        <span
            className={`relative inline-flex ${className}`}
            onMouseEnter={show}
            onMouseLeave={hide}
            onFocus={show}
            onBlur={hide}
        >
            <span aria-describedby={open ? tooltipId : undefined}>
                {children}
            </span>

            <span
                role="tooltip"
                id={tooltipId}
                className={[
                    "pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 -translate-x-1/2",
                    "whitespace-pre-line rounded-md bg-gray-900 px-2.5 py-1.5",
                    "text-xs font-medium leading-snug text-white shadow-lg",
                    "transition-opacity duration-150 ease-out",
                    "motion-reduce:transition-none motion-reduce:transform-none",
                    open ? "opacity-100" : "opacity-0",
                ].join(" ")}
                aria-hidden={open ? undefined : "true"}
            >
                {content}
            </span>
        </span>
    );
}
