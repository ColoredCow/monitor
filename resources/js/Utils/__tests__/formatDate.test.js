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

    it("returns empty string for malformed input (non-date string)", () => {
        expect(formatDateUTC("not-a-date")).toBe("");
    });

    it("returns empty string for malformed input (non-numeric date part)", () => {
        expect(formatDateUTC("abcd-ef-gh")).toBe("");
    });

    it("parses Laravel ISO-8601 with microseconds and Z suffix", () => {
        expect(formatDateUTC("2026-03-27T15:00:00.000000Z")).toBe("27 Mar 2026");
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

    it("returns empty string for malformed input (garbage after date)", () => {
        expect(formatDateTimeUTC("2026-03-27 garbage")).toBe("");
    });

    it("parses Laravel ISO-8601 with microseconds and Z suffix", () => {
        expect(formatDateTimeUTC("2026-03-27T15:00:00.000000Z")).toBe(
            "27 Mar 2026, 15:00"
        );
    });

    it("parses ISO-8601 with a Z suffix but no fractional seconds", () => {
        expect(formatDateTimeUTC("2026-03-27T15:00:00Z")).toBe(
            "27 Mar 2026, 15:00"
        );
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

    it("returns empty string for malformed input (non-date string)", () => {
        expect(formatRelative("not-a-date", base)).toBe("");
    });

    it("parses Laravel ISO-8601 timestamps (microseconds + Z) for the live-status label", () => {
        // The monitor model's datetime cast serializes uptime_last_check_date
        // as e.g. '2026-03-27T14:55:00.000000Z' — this must not resolve to "".
        expect(formatRelative("2026-03-27T14:55:00.000000Z", base)).toBe(
            "5m ago"
        );
    });
});
