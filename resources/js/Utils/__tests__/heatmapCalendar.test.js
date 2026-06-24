import { describe, it, expect } from "vitest";
import {
    buildYearGrid,
    monthLabelColumns,
    computeCellSize,
} from "@/Utils/heatmapCalendar";

describe("buildYearGrid", () => {
    it("starts each week on Sunday and ends on Saturday", () => {
        const { weeks } = buildYearGrid(2026);
        for (const week of weeks) {
            expect(week).toHaveLength(7);
        }
        // 2026-01-01 is a Thursday, so the first week's Sunday is 2025-12-28.
        expect(weeks[0][0].iso).toBe("2025-12-28");
        expect(weeks[0][0].inYear).toBe(false);
    });

    it("includes Jan 1 and Dec 31 of the target year as inYear cells", () => {
        const { weeks } = buildYearGrid(2026);
        const flat = weeks.flat();
        const jan1 = flat.find((c) => c.iso === "2026-01-01");
        const dec31 = flat.find((c) => c.iso === "2026-12-31");
        expect(jan1.inYear).toBe(true);
        expect(dec31.inYear).toBe(true);
    });

    it("marks padding days as inYear:false", () => {
        const { weeks } = buildYearGrid(2026);
        const flat = weeks.flat();
        const padBefore = flat.find((c) => c.iso === "2025-12-31");
        expect(padBefore.inYear).toBe(false);
    });

    it("counts exactly 365 in-year days for a common year", () => {
        const { weeks } = buildYearGrid(2026);
        const inYearCount = weeks.flat().filter((c) => c.inYear).length;
        expect(inYearCount).toBe(365);
    });

    it("counts exactly 366 in-year days for the 2024 leap year", () => {
        const { weeks } = buildYearGrid(2024);
        const flat = weeks.flat();
        const inYearCount = flat.filter((c) => c.inYear).length;
        expect(inYearCount).toBe(366);
        expect(flat.find((c) => c.iso === "2024-02-29").inYear).toBe(true);
    });

    it("pads to whole weeks (total cells divisible by 7)", () => {
        const { weeks } = buildYearGrid(2024);
        expect(weeks.flat().length % 7).toBe(0);
    });
});

describe("monthLabelColumns", () => {
    it("returns 12 month labels for a full year by default spacing", () => {
        const { weeks } = buildYearGrid(2026);
        const labels = monthLabelColumns(weeks);
        expect(labels[0].label).toBe("Jan");
        expect(labels.every((l) => /^[A-Z][a-z]{2}$/.test(l.label))).toBe(
            true
        );
        // colIndex must be a valid, ascending week column.
        for (let i = 1; i < labels.length; i += 1) {
            expect(labels[i].colIndex).toBeGreaterThan(labels[i - 1].colIndex);
            expect(labels[i].colIndex).toBeLessThan(weeks.length);
        }
    });

    it("drops a label that is fewer than 3 columns from the previous kept one", () => {
        // Two months whose day-1 columns are only 2 apart -> second is dropped.
        const weeks = [
            [{ iso: "2026-01-01", inYear: true }],
            [{ iso: "2026-01-08", inYear: true }],
            [{ iso: "2026-02-01", inYear: true }],
        ];
        const labels = monthLabelColumns(weeks);
        expect(labels).toHaveLength(1);
        expect(labels[0]).toEqual({ label: "Jan", colIndex: 0 });
    });

    it("keeps a label that is at least 3 columns from the previous kept one", () => {
        const weeks = [
            [{ iso: "2026-01-01", inYear: true }],
            [{ iso: "2026-01-08", inYear: true }],
            [{ iso: "2026-01-15", inYear: true }],
            [{ iso: "2026-02-01", inYear: true }],
        ];
        const labels = monthLabelColumns(weeks);
        expect(labels).toEqual([
            { label: "Jan", colIndex: 0 },
            { label: "Feb", colIndex: 3 },
        ]);
    });
});

describe("computeCellSize", () => {
    const opts = { gap: 4, min: 8, max: 16 };

    it("fits the cells plus gaps inside the container width", () => {
        // 53 weeks, container 900px: (size+gap)*weeks - gap <= width.
        const size = computeCellSize(900, 53, opts);
        expect(size).toBeGreaterThanOrEqual(opts.min);
        expect(size).toBeLessThanOrEqual(opts.max);
        expect((size + opts.gap) * 53 - opts.gap).toBeLessThanOrEqual(900);
    });

    it("clamps to max when the container is very wide", () => {
        expect(computeCellSize(5000, 53, opts)).toBe(16);
    });

    it("clamps to min when the container is very narrow", () => {
        expect(computeCellSize(100, 53, opts)).toBe(8);
    });

    it("returns an integer", () => {
        expect(Number.isInteger(computeCellSize(763, 53, opts))).toBe(true);
    });

    it("falls back to min for non-positive width", () => {
        expect(computeCellSize(0, 53, opts)).toBe(8);
    });
});
