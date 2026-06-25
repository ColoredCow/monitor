import { describe, it, expect } from "vitest";
import { stripSlots } from "@/Utils/recentStrip";

const c = (id) => ({ id });

describe("stripSlots", () => {
    it("returns all-gray (null) when there are no checks", () => {
        expect(stripSlots([], 3)).toEqual([null, null, null]);
    });

    it("right-aligns checks (newest last) and gray-pads the left", () => {
        // newest-first input: c3 newest, c1 oldest
        expect(stripSlots([c(3), c(2), c(1)], 5)).toEqual([
            null,
            null,
            c(1),
            c(2),
            c(3),
        ]);
    });

    it("fills exactly with no padding when checks === capacity", () => {
        expect(stripSlots([c(3), c(2), c(1)], 3)).toEqual([c(1), c(2), c(3)]);
    });

    it("keeps only the most-recent `capacity` checks (newest on the right)", () => {
        const five = [c(5), c(4), c(3), c(2), c(1)]; // newest-first
        expect(stripSlots(five, 3)).toEqual([c(3), c(4), c(5)]);
    });

    it("handles zero/negative capacity safely", () => {
        expect(stripSlots([c(1)], 0)).toEqual([]);
        expect(stripSlots([c(1)], -2)).toEqual([]);
    });
});
