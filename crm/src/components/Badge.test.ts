import { describe, expect, it } from "vitest";
import { getBadgeVariant } from "../components/Badge";

describe("Badge component helpers", () => {
  it("maps active status to success variant", () => {
    expect(getBadgeVariant("ACTIVE")).toBe("success");
  });

  it("maps suspended status to warning variant", () => {
    expect(getBadgeVariant("SUSPENDED")).toBe("warning");
  });

  it("maps revoked status to danger variant", () => {
    expect(getBadgeVariant("REVOKED")).toBe("danger");
  });

  it("defaults unknown statuses to neutral", () => {
    expect(getBadgeVariant("CUSTOM_STATUS")).toBe("neutral");
  });

  it("is case-insensitive", () => {
    expect(getBadgeVariant("active")).toBe("success");
  });
});
