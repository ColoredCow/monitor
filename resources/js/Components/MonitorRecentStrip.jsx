import React, { useEffect, useMemo, useRef, useState } from "react";
import { formatDateTimeUTC } from "@/Utils/formatDate";
import {
    getCheckStatusMeta,
    normalizeCheckStatus,
    statusesForCheckType,
} from "@/Utils/checkStatusSeverity";
import { SINGLE_STATUS_CLASS } from "@/Utils/heatmapCell";
import { stripSlots } from "@/Utils/recentStrip";
import Tooltip from "@/Components/Tooltip";

const SEGMENT_WIDTH = 8;
const SEGMENT_GAP = 2;
const MAX_SLOTS = 150;

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

export default function MonitorRecentStrip({ checkType, checks = [] }) {
    const containerRef = useRef(null);
    const [capacity, setCapacity] = useState(MAX_SLOTS);

    // How many fixed-width slots fit, capped at MAX_SLOTS.
    useEffect(() => {
        const element = containerRef.current;
        if (!element) {
            return undefined;
        }

        const measure = () => {
            const width = element.clientWidth;
            const perSegment = SEGMENT_WIDTH + SEGMENT_GAP;
            const fit = Math.max(
                1,
                Math.floor((width + SEGMENT_GAP) / perSegment)
            );
            setCapacity(Math.min(MAX_SLOTS, fit));
        };

        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(element);
        return () => observer.disconnect();
    }, [checks.length]);

    const slots = useMemo(
        () => stripSlots(checks, capacity),
        [checks, capacity]
    );
    const shown = slots.filter(Boolean).length;
    const legendStatuses = statusesForCheckType(checkType);

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div className="mb-2 flex items-baseline gap-x-2">
                <span className="text-sm font-medium text-gray-900">
                    Recent checks
                </span>
                <span className="text-xs text-gray-500 tabular-nums">
                    last {shown}
                </span>
            </div>

            <div ref={containerRef} className="flex items-stretch py-0.5">
                <div className="flex" style={{ gap: SEGMENT_GAP }}>
                    {slots.map((check, index) =>
                        check ? (
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
                                        "h-8 rounded-sm border transition-opacity duration-150 ease-out",
                                        "hover:opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500",
                                        "motion-reduce:transition-none",
                                        SINGLE_STATUS_CLASS[
                                            normalizeCheckStatus(check.status)
                                        ],
                                    ].join(" ")}
                                    style={{ width: SEGMENT_WIDTH }}
                                />
                            </Tooltip>
                        ) : (
                            <div
                                key={`gap-${index}`}
                                aria-hidden="true"
                                className="h-8 rounded-sm border bg-gray-100 border-gray-200"
                                style={{ width: SEGMENT_WIDTH }}
                            />
                        )
                    )}
                </div>
            </div>

            <div className="mt-3 flex items-center flex-wrap gap-x-4 gap-y-2 text-xs text-gray-600">
                {legendStatuses.map((status) => (
                    <span key={status} className="flex items-center gap-1.5">
                        <span
                            className={`h-3 w-3 rounded-sm border ${SINGLE_STATUS_CLASS[status]}`}
                        />
                        {getCheckStatusMeta(status).label}
                    </span>
                ))}
            </div>
        </div>
    );
}
