import { describe, it, expect } from "vitest";
import {
    formatDateUTC,
    formatDateTimeUTC,
    formatRelative,
} from "@/Utils/formatDate";

describe("formatDateUTC", () => {
    it("formats a date-only string as '27 Mar 2026'", () => {
        expect(formatDateUTC("2026-03-27")).toBe("27 Mar 2026");
    });

    it("formats a datetime string using its date part", () => {
        expect(formatDateUTC("2026-03-27 15:00:00")).toBe("27 Mar 2026");
    });

    it("formats a leap day correctly", () => {
        expect(formatDateUTC("2024-02-29")).toBe("29 Feb 2024");
    });

    it("does not shift across days at UTC midnight", () => {
        expect(formatDateUTC("2026-01-01 00:00:00")).toBe("01 Jan 2026");
    });

    it("returns empty string for null/empty input", () => {
        expect(formatDateUTC(null)).toBe("");
        expect(formatDateUTC("")).toBe("");
    });
});

describe("formatDateTimeUTC", () => {
    it("formats a datetime as '27 Mar 2026, 15:00'", () => {
        expect(formatDateTimeUTC("2026-03-27 15:00:00")).toBe(
            "27 Mar 2026, 15:00"
        );
    });

    it("pads single-digit hours and minutes", () => {
        expect(formatDateTimeUTC("2026-03-27 09:05:00")).toBe(
            "27 Mar 2026, 09:05"
        );
    });

    it("renders midnight as 00:00 (24h, no shift)", () => {
        expect(formatDateTimeUTC("2026-01-01 00:00:00")).toBe(
            "01 Jan 2026, 00:00"
        );
    });

    it("returns empty string for null input", () => {
        expect(formatDateTimeUTC(null)).toBe("");
    });
});

describe("formatRelative", () => {
    const base = Date.UTC(2026, 2, 27, 15, 0, 0); // 2026-03-27 15:00:00 UTC

    it("returns 'just now' for under a minute", () => {
        expect(formatRelative("2026-03-27 14:59:30", base)).toBe("just now");
    });

    it("returns minutes for under an hour", () => {
        expect(formatRelative("2026-03-27 14:55:00", base)).toBe("5m ago");
    });

    it("returns hours for under a day", () => {
        expect(formatRelative("2026-03-27 12:00:00", base)).toBe("3h ago");
    });

    it("returns days for a day or more", () => {
        expect(formatRelative("2026-03-25 15:00:00", base)).toBe("2d ago");
    });

    it("clamps future timestamps to 'just now'", () => {
        expect(formatRelative("2026-03-27 15:00:30", base)).toBe("just now");
    });

    it("returns empty string for null input", () => {
        expect(formatRelative(null, base)).toBe("");
    });
});
