import { describe, it, expect } from "vitest";
import { cellColorClass, cellMetricLines } from "@/Utils/heatmapCell";

const upDay = (overrides = {}) => ({
    date: "2026-08-01",
    total_checks: 8,
    successful_checks: 8,
    warning_checks: 0,
    failed_checks: 0,
    success_ratio: 100,
    worst_status: "success",
    avg_response_time_ms: 157,
    p95_response_time_ms: 205,
    ...overrides,
});

describe("cellColorClass", () => {
    it("returns the no-checks gray for empty/zero days regardless of type", () => {
        expect(cellColorClass(null, "uptime")).toBe("bg-gray-100 border-gray-200");
        expect(cellColorClass({ total_checks: 0 }, "domain")).toBe(
            "bg-gray-100 border-gray-200"
        );
    });

    it("colors uptime by success ratio in 4 bands (100 / 95+ / 90+ / <90)", () => {
        expect(cellColorClass(upDay({ success_ratio: 100 }), "uptime")).toBe(
            "bg-green-600 border-green-600"
        );
        expect(cellColorClass(upDay({ success_ratio: 95 }), "uptime")).toBe(
            "bg-green-400 border-green-400"
        );
        expect(cellColorClass(upDay({ success_ratio: 99.9 }), "uptime")).toBe(
            "bg-green-400 border-green-400"
        );
        expect(cellColorClass(upDay({ success_ratio: 90 }), "uptime")).toBe(
            "bg-amber-400 border-amber-400"
        );
        expect(cellColorClass(upDay({ success_ratio: 94.9 }), "uptime")).toBe(
            "bg-amber-400 border-amber-400"
        );
        expect(
            cellColorClass(upDay({ worst_status: "failed", success_ratio: 89.9 }), "uptime")
        ).toBe("bg-red-500 border-red-500");
        expect(
            cellColorClass(upDay({ worst_status: "failed", success_ratio: 0 }), "uptime")
        ).toBe("bg-red-500 border-red-500");
    });

    it("keeps an all-unknown uptime day gray, not red", () => {
        expect(
            cellColorClass(
                {
                    total_checks: 2,
                    successful_checks: 0,
                    warning_checks: 0,
                    failed_checks: 0,
                    success_ratio: 0,
                    worst_status: "unknown",
                },
                "uptime"
            )
        ).toBe("bg-gray-300 border-gray-300");
    });

    it("uses a SINGLE solid color per status for domain (no bands)", () => {
        expect(
            cellColorClass({ total_checks: 1, worst_status: "success", success_ratio: 100 }, "domain")
        ).toBe("bg-green-600 border-green-600");
        expect(
            cellColorClass({ total_checks: 1, worst_status: "warning", success_ratio: 0 }, "domain")
        ).toBe("bg-orange-400 border-orange-400");
        expect(
            cellColorClass({ total_checks: 1, worst_status: "failed", success_ratio: 0 }, "domain")
        ).toBe("bg-red-600 border-red-600");
    });

    it("uses single solid colors for certificate too", () => {
        expect(
            cellColorClass({ total_checks: 1, worst_status: "success", success_ratio: 100 }, "certificate")
        ).toBe("bg-green-600 border-green-600");
        expect(
            cellColorClass({ total_checks: 1, worst_status: "failed", success_ratio: 0 }, "certificate")
        ).toBe("bg-red-600 border-red-600");
    });
});

describe("cellMetricLines", () => {
    it("returns a single No-checks line for empty days", () => {
        expect(cellMetricLines(null, "uptime")).toEqual(["No checks"]);
        expect(cellMetricLines({ total_checks: 0 }, "domain")).toEqual(["No checks"]);
    });

    it("uptime omits the Warning row, labels the ratio 'Uptime', and includes latency when present", () => {
        const lines = cellMetricLines(upDay(), "uptime");
        expect(lines).toContain("Total checks: 8");
        expect(lines).toContain("Success: 8");
        expect(lines).toContain("Failed: 0");
        expect(lines.some((l) => l.startsWith("Warning:"))).toBe(false);
        expect(lines).toContain("Uptime: 100%");
        expect(lines.some((l) => l.startsWith("Success ratio"))).toBe(false);
        expect(lines).toContain("Avg response: 157ms");
        expect(lines).toContain("P95 response: 205ms");
    });

    it("uptime omits latency lines entirely when not measured (null)", () => {
        const lines = cellMetricLines(
            upDay({ avg_response_time_ms: null, p95_response_time_ms: null }),
            "uptime"
        );
        expect(lines.some((l) => l.startsWith("Avg response"))).toBe(false);
        expect(lines.some((l) => l.startsWith("P95 response"))).toBe(false);
    });

    it("domain includes the Warning row, labels the ratio 'Success ratio', and NEVER shows latency", () => {
        const lines = cellMetricLines(
            {
                total_checks: 1,
                successful_checks: 0,
                warning_checks: 1,
                failed_checks: 0,
                success_ratio: 0,
                worst_status: "warning",
                avg_response_time_ms: 157,
                p95_response_time_ms: 205,
            },
            "domain"
        );
        expect(lines).toContain("Warning: 1");
        expect(lines).toContain("Success: 0");
        expect(lines).toContain("Failed: 0");
        expect(lines).toContain("Success ratio: 0%");
        expect(lines.some((l) => l.startsWith("Uptime:"))).toBe(false);
        expect(lines.some((l) => l.startsWith("Avg response"))).toBe(false);
        expect(lines.some((l) => l.startsWith("P95 response"))).toBe(false);
    });

    it("certificate omits Warning row and latency", () => {
        const lines = cellMetricLines(
            { total_checks: 1, successful_checks: 1, warning_checks: 0, failed_checks: 0, success_ratio: 100, worst_status: "success" },
            "certificate"
        );
        expect(lines.some((l) => l.startsWith("Warning:"))).toBe(false);
        expect(lines.some((l) => l.startsWith("Avg response"))).toBe(false);
    });
});
