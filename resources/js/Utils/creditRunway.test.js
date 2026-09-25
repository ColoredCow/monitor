import { describe, it, expect } from "vitest";
import { dailyBurnForConfig, runwayLabel } from "@/Utils/creditRunway";

describe("dailyBurnForConfig", () => {
    it("charges 1440/interval for uptime", () => {
        expect(
            dailyBurnForConfig({ intervalMinutes: "5", uptimeEnabled: true, domainEnabled: false })
        ).toBe(288);
    });

    it("adds 1/day each for certificate and domain", () => {
        expect(
            dailyBurnForConfig({
                intervalMinutes: "1",
                uptimeEnabled: true,
                certificateEnabled: true,
                domainEnabled: true,
            })
        ).toBe(1442);
    });

    it("burns nothing when all checks are off", () => {
        expect(
            dailyBurnForConfig({ intervalMinutes: "5", uptimeEnabled: false, domainEnabled: false })
        ).toBe(0);
    });

    it("floors bad intervals at one minute", () => {
        expect(
            dailyBurnForConfig({ intervalMinutes: "0", uptimeEnabled: true, domainEnabled: false })
        ).toBe(1440);
        expect(
            dailyBurnForConfig({ intervalMinutes: "garbage", uptimeEnabled: true, domainEnabled: false })
        ).toBe(1440);
    });
});

describe("runwayLabel", () => {
    it("handles zero burn before zero balance", () => {
        expect(runwayLabel(0, 0)).toBe("credits aren't being consumed");
        expect(runwayLabel(500, 0)).toBe("credits aren't being consumed");
    });

    it("reports out of credits", () => {
        expect(runwayLabel(0, 288)).toBe("out of credits");
        expect(runwayLabel(-3, 288)).toBe("out of credits");
    });

    it("picks human units by magnitude", () => {
        expect(runwayLabel(288 * 400, 288)).toBe("over a year");
        expect(runwayLabel(288 * 90, 288)).toBe("~3 months");
        expect(runwayLabel(288 * 21, 288)).toBe("~3 weeks");
        expect(runwayLabel(288 * 7, 288)).toBe("~7 days");
        expect(runwayLabel(288, 288)).toBe("~1 day");
        expect(runwayLabel(216, 288)).toBe("~18 hours");
        expect(runwayLabel(5, 288)).toBe("less than an hour");
    });
});
