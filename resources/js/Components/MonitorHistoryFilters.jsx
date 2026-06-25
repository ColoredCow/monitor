import React, { useEffect, useState } from "react";

const PRESETS = [
    { value: "7d", label: "7d" },
    { value: "30d", label: "30d" },
    { value: "all", label: "All" },
];

function todayIso() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, "0");
    const day = String(now.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
}

export default function MonitorHistoryFilters({ filters, pending = false, onApply }) {
    const activePreset = filters?.preset || "30d";

    const [customRange, setCustomRange] = useState({
        from: filters?.from || "",
        to: filters?.to || "",
    });

    // Keep inputs in sync with the range the server actually applied (it may clamp/swap).
    useEffect(() => {
        setCustomRange({ from: filters?.from || "", to: filters?.to || "" });
    }, [filters?.from, filters?.to]);

    const max = todayIso();

    const handlePreset = (value) => {
        onApply({ preset: value });
    };

    const submitCustomRange = (event) => {
        event.preventDefault();
        onApply({ preset: "custom", from: customRange.from, to: customRange.to });
    };

    const segmentBase =
        "h-9 px-3 inline-flex items-center justify-center text-xs font-semibold border focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:ring-offset-1 transition-colors duration-150 ease-out motion-reduce:transition-none disabled:opacity-50 disabled:cursor-not-allowed";

    const dateInputClass =
        "h-9 px-3 text-sm font-medium tabular-nums text-gray-900 bg-white border border-gray-300 rounded-lg shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:border-transparent disabled:opacity-50";

    return (
        <div className="flex flex-wrap items-end gap-x-4 gap-y-3">
            <div
                className="inline-flex rounded-lg shadow-sm"
                role="group"
                aria-label="Date range presets"
            >
                {PRESETS.map((preset, index) => {
                    const isActive = activePreset === preset.value;
                    return (
                        <button
                            key={preset.value}
                            type="button"
                            aria-pressed={isActive}
                            disabled={pending}
                            onClick={() => handlePreset(preset.value)}
                            className={[
                                segmentBase,
                                index === 0 ? "rounded-l-lg" : "-ml-px",
                                index === PRESETS.length - 1 ? "rounded-r-lg" : "",
                                isActive
                                    ? "bg-purple-600 text-white border-purple-600 hover:bg-purple-700 active:bg-purple-800 z-10"
                                    : "bg-white text-gray-700 border-gray-300 hover:bg-gray-50 active:bg-gray-100",
                            ].join(" ")}
                        >
                            {preset.label}
                        </button>
                    );
                })}
            </div>

            <form
                onSubmit={submitCustomRange}
                className="flex flex-wrap items-end gap-x-3 gap-y-3"
            >
                <div className="flex flex-col">
                    <label
                        htmlFor="history-filter-from"
                        className="mb-1 text-xs font-medium text-gray-600"
                    >
                        From
                    </label>
                    <input
                        id="history-filter-from"
                        type="date"
                        name="from"
                        max={max}
                        value={customRange.from}
                        disabled={pending}
                        onChange={(event) =>
                            setCustomRange((previous) => ({
                                ...previous,
                                from: event.target.value,
                            }))
                        }
                        className={dateInputClass}
                    />
                </div>
                <div className="flex flex-col">
                    <label
                        htmlFor="history-filter-to"
                        className="mb-1 text-xs font-medium text-gray-600"
                    >
                        To
                    </label>
                    <input
                        id="history-filter-to"
                        type="date"
                        name="to"
                        max={max}
                        value={customRange.to}
                        disabled={pending}
                        onChange={(event) =>
                            setCustomRange((previous) => ({
                                ...previous,
                                to: event.target.value,
                            }))
                        }
                        className={dateInputClass}
                    />
                </div>
                <button
                    type="submit"
                    disabled={pending}
                    className="h-9 px-4 inline-flex items-center justify-center rounded-lg bg-purple-600 text-sm font-semibold text-white shadow-sm hover:bg-purple-700 active:bg-purple-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:ring-offset-1 transition-colors duration-150 ease-out motion-reduce:transition-none disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    Apply
                </button>
            </form>
        </div>
    );
}
