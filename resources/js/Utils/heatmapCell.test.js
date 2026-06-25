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

    it("grades uptime by success ratio (3 shades per status)", () => {
        expect(cellColorClass(upDay({ success_ratio: 100 }), "uptime")).toBe(
            "bg-green-700 border-green-700"
        );
        expect(cellColorClass(upDay({ success_ratio: 96 }), "uptime")).toBe(
            "bg-green-500 border-green-500"
        );
        expect(cellColorClass(upDay({ success_ratio: 80 }), "uptime")).toBe(
            "bg-green-300 border-green-300"
        );
        expect(
            cellColorClass(upDay({ worst_status: "failed", success_ratio: 0 }), "uptime")
        ).toBe("bg-red-700 border-red-700");
    });

    it("uses a SINGLE solid color per status for domain (no gradient)", () => {
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

    it("uptime omits the Warning row and includes latency when present", () => {
        const lines = cellMetricLines(upDay(), "uptime");
        expect(lines).toContain("Total checks: 8");
        expect(lines).toContain("Success: 8");
        expect(lines).toContain("Failed: 0");
        expect(lines.some((l) => l.startsWith("Warning:"))).toBe(false);
        expect(lines).toContain("Success ratio: 100%");
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

    it("domain includes the Warning row and NEVER shows latency", () => {
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
