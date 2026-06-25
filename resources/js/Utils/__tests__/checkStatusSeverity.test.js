import { describe, it, expect } from "vitest";
import {
    CHECK_TYPE_STATUSES,
    statusesForCheckType,
} from "@/Utils/checkStatusSeverity";

describe("CHECK_TYPE_STATUSES", () => {
    it("uptime omits warning", () => {
        expect(CHECK_TYPE_STATUSES.uptime).toEqual([
            "success",
            "failed",
            "unknown",
        ]);
    });

    it("certificate omits warning", () => {
        expect(CHECK_TYPE_STATUSES.certificate).toEqual([
            "success",
            "failed",
            "unknown",
        ]);
    });

    it("domain includes warning", () => {
        expect(CHECK_TYPE_STATUSES.domain).toEqual([
            "success",
            "warning",
            "failed",
            "unknown",
        ]);
    });
});

describe("statusesForCheckType", () => {
    it("returns the uptime list for 'uptime'", () => {
        expect(statusesForCheckType("uptime")).toEqual([
            "success",
            "failed",
            "unknown",
        ]);
    });

    it("returns the domain list for 'domain'", () => {
        expect(statusesForCheckType("domain")).toEqual([
            "success",
            "warning",
            "failed",
            "unknown",
        ]);
    });

    it("returns the certificate list for 'certificate'", () => {
        expect(statusesForCheckType("certificate")).toEqual([
            "success",
            "failed",
            "unknown",
        ]);
    });

    it("falls back to all four statuses for an unknown type", () => {
        expect(statusesForCheckType("bogus")).toEqual([
            "success",
            "warning",
            "failed",
            "unknown",
        ]);
    });

    it("falls back for undefined input", () => {
        expect(statusesForCheckType(undefined)).toEqual([
            "success",
            "warning",
            "failed",
            "unknown",
        ]);
    });

    it("returns a copy, not the shared array reference", () => {
        const result = statusesForCheckType("uptime");
        result.push("mutated");
        expect(CHECK_TYPE_STATUSES.uptime).toEqual([
            "success",
            "failed",
            "unknown",
        ]);
    });
});
