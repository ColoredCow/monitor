import { describe, it, expect } from "vitest";
import { buildHistoryParams } from "@/Utils/historyParams";

describe("buildHistoryParams", () => {
    const current = {
        year: 2026,
        preset: "30d",
        from: "2026-05-01",
        to: "2026-05-31",
        recent_type: "uptime",
        recent_page: 3,
    };

    it("returns the full current param set when no overrides are given", () => {
        expect(buildHistoryParams(current, {})).toEqual({
            year: 2026,
            preset: "30d",
            from: "2026-05-01",
            to: "2026-05-31",
            recent_type: "uptime",
            recent_page: 3,
        });
    });

    it("merges overrides over the current set without mutating current", () => {
        const result = buildHistoryParams(current, { year: 2025 });
        expect(result.year).toBe(2025);
        expect(result.preset).toBe("30d");
        expect(current.year).toBe(2026);
    });

    it("applies multiple overrides at once", () => {
        const result = buildHistoryParams(current, {
            recent_type: "domain",
            recent_page: 1,
        });
        expect(result.recent_type).toBe("domain");
        expect(result.recent_page).toBe(1);
    });

    it("drops keys whose override value is null or undefined", () => {
        const result = buildHistoryParams(current, { from: null, to: undefined });
        expect("from" in result).toBe(false);
        expect("to" in result).toBe(false);
        expect(result.preset).toBe("30d");
    });

    it("tolerates a partial current object", () => {
        expect(buildHistoryParams({ year: 2026 }, { recent_page: 2 })).toEqual({
            year: 2026,
            recent_page: 2,
        });
    });
});
