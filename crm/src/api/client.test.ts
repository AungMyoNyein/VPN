import { describe, expect, it } from "vitest";
import { parseApiError, ApiClientError } from "./client";

describe("parseApiError", () => {
  it("parses canonical API error envelope", () => {
    const err = parseApiError(
      { error: { code: "UNAUTHENTICATED", message: "Invalid credentials.", request_id: "req-1" } },
      401,
    );
    expect(err).toBeInstanceOf(ApiClientError);
    expect(err.code).toBe("UNAUTHENTICATED");
    expect(err.message).toBe("Invalid credentials.");
    expect(err.status).toBe(401);
    expect(err.requestId).toBe("req-1");
  });

  it("returns unknown error for non-envelope bodies", () => {
    const err = parseApiError(null, 500);
    expect(err.code).toBe("UNKNOWN");
    expect(err.status).toBe(500);
  });

  it("handles forbidden errors", () => {
    const err = parseApiError(
      { error: { code: "FORBIDDEN", message: "Admin account is disabled." } },
      403,
    );
    expect(err.code).toBe("FORBIDDEN");
    expect(err.message).toContain("disabled");
  });
});
