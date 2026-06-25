import React, { useEffect, useId, useLayoutEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";

// Reusable hover + keyboard-focus tooltip.
// Rendered through a portal to <body> with fixed positioning so it is NEVER
// clipped by an ancestor's `overflow` (e.g. the heatmap's overflow-x-auto
// scroll container). Positioned above the trigger and centered, flipping below
// and clamping horizontally when it would leave the viewport.
// - role="tooltip" + aria-describedby wired to the trigger for screen readers.
// - Shows on mouse hover AND keyboard focus; pointer-events disabled so it
//   never steals hover. Hides on scroll/resize to avoid a stale position.
const MARGIN = 8;

export default function Tooltip({ content, children, className = "" }) {
    const tooltipId = useId();
    const triggerRef = useRef(null);
    const tooltipRef = useRef(null);
    const [open, setOpen] = useState(false);
    const [pos, setPos] = useState({ top: 0, left: 0 });

    const show = () => setOpen(true);
    const hide = () => setOpen(false);

    // Position before paint so there is no flash at (0,0).
    useLayoutEffect(() => {
        if (!open || !triggerRef.current || !tooltipRef.current) {
            return;
        }
        const trigger = triggerRef.current.getBoundingClientRect();
        const tip = tooltipRef.current.getBoundingClientRect();

        let top = trigger.top - tip.height - MARGIN;
        if (top < MARGIN) {
            top = trigger.bottom + MARGIN; // not enough room above -> flip below
        }

        let left = trigger.left + trigger.width / 2 - tip.width / 2;
        left = Math.max(
            MARGIN,
            Math.min(left, window.innerWidth - tip.width - MARGIN)
        );

        setPos({ top, left });
    }, [open, content]);

    // A stale fixed-position tooltip looks broken after the page scrolls; just hide.
    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onMove = () => hide();
        window.addEventListener("scroll", onMove, true);
        window.addEventListener("resize", onMove);
        return () => {
            window.removeEventListener("scroll", onMove, true);
            window.removeEventListener("resize", onMove);
        };
    }, [open]);

    if (content === null || content === undefined || content === "") {
        return children;
    }

    return (
        <span
            ref={triggerRef}
            className={`inline-flex ${className}`}
            onMouseEnter={show}
            onMouseLeave={hide}
            onFocus={show}
            onBlur={hide}
        >
            <span aria-describedby={open ? tooltipId : undefined}>{children}</span>

            {open && typeof document !== "undefined"
                ? createPortal(
                      <span
                          ref={tooltipRef}
                          role="tooltip"
                          id={tooltipId}
                          style={{ position: "fixed", top: pos.top, left: pos.left }}
                          className={[
                              "pointer-events-none z-50 w-max max-w-xs",
                              "whitespace-pre-line rounded-md bg-gray-900 px-3 py-2",
                              "text-xs font-medium leading-snug text-white shadow-lg",
                          ].join(" ")}
                      >
                          {content}
                      </span>,
                      document.body
                  )
                : null}
        </span>
    );
}
