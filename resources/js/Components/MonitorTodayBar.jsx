import React, { useEffect, useMemo, useRef, useState } from "react";
import { formatDateTimeUTC } from "@/Utils/formatDate";
import {
    getCheckStatusMeta,
    statusesForCheckType,
} from "@/Utils/checkStatusSeverity";
import Tooltip from "@/Components/Tooltip";

const SEGMENT_WIDTH = 8;
const SEGMENT_GAP = 2;

const LEGEND_SWATCH = {
    success: "bg-green-500",
    warning: "bg-yellow-400",
    failed: "bg-red-500",
    unknown: "bg-gray-300",
};

function buildSegmentTooltip(check) {
    return [
        formatDateTimeUTC(check.checked_at),
        `Status: ${getCheckStatusMeta(check.status).label}`,
        check.message ? `Message: ${check.message}` : null,
        check.response_time_ms !== null && check.response_time_ms !== undefined
            ? `Response: ${check.response_time_ms}ms`
            : null,
        check.failure_reason ? `Failure: ${check.failure_reason}` : null,
    ]
        .filter(Boolean)
        .join("\n");
}

export default function MonitorTodayBar({ checkType, checks = [] }) {
    const containerRef = useRef(null);
    const [maxSegments, setMaxSegments] = useState(checks.length);

    // Measure how many newest-first segments fit; show the most recent that fit.
    useEffect(() => {
        const element = containerRef.current;
        if (!element) {
            return undefined;
        }

        const measure = () => {
            const width = element.clientWidth;
            const perSegment = SEGMENT_WIDTH + SEGMENT_GAP;
            const fit = Math.max(1, Math.floor((width + SEGMENT_GAP) / perSegment));
            setMaxSegments(fit);
        };

        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(element);
        return () => observer.disconnect();
    }, [checks.length]);

    // checks are newest-first; keep the most recent that fit, then render
    // oldest->newest left-to-right so the newest sits on the right edge.
    const visible = useMemo(() => {
        return checks.slice(0, maxSegments).reverse();
    }, [checks, maxSegments]);

    const legendStatuses = statusesForCheckType(checkType);

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p className="mb-2 text-xs font-medium text-gray-600 tabular-nums">
                {`Today (${checks.length} checks)`}
            </p>

            <div ref={containerRef} className="flex items-stretch overflow-hidden">
                {visible.length === 0 ? (
                    <span className="text-xs text-gray-600">
                        No checks recorded today.
                    </span>
                ) : (
                    <div className="flex" style={{ gap: SEGMENT_GAP }}>
                        {visible.map((check) => (
                            <Tooltip
                                key={check.id}
                                content={buildSegmentTooltip(check)}
                            >
                                <div
                                    tabIndex={0}
                                    aria-label={buildSegmentTooltip(check).replace(
                                        /\n/g,
                                        ", "
                                    )}
                                    className={[
                                        "h-8 rounded-sm transition-transform duration-150 ease-out",
                                        "hover:scale-y-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500",
                                        "motion-reduce:transition-none motion-reduce:transform-none",
                                        getCheckStatusMeta(check.status).heatmapClass,
                                    ].join(" ")}
                                    style={{ width: SEGMENT_WIDTH }}
                                />
                            </Tooltip>
                        ))}
                    </div>
                )}
            </div>

            <div className="mt-3 flex items-center flex-wrap gap-x-4 gap-y-2 text-xs text-gray-600">
                {legendStatuses.map((status) => (
                    <span key={status} className="flex items-center gap-1.5">
                        <span
                            className={`h-3 w-3 rounded-sm ${
                                LEGEND_SWATCH[status] || "bg-gray-300"
                            }`}
                        />
                        {getCheckStatusMeta(status).label}
                    </span>
                ))}
            </div>
        </div>
    );
}
