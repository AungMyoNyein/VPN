import { describe, expect, it } from "vitest";
import { shouldRedirectToLogin, getPostLoginRedirect } from "./auth";

describe("auth redirect helpers", () => {
  it("redirects unauthenticated users away from protected routes", () => {
    expect(shouldRedirectToLogin(false, false, "/customers")).toBe(true);
  });

  it("does not redirect while session is loading", () => {
    expect(shouldRedirectToLogin(false, true, "/customers")).toBe(false);
  });

  it("does not redirect authenticated users", () => {
    expect(shouldRedirectToLogin(true, false, "/customers")).toBe(false);
  });

  it("does not redirect on login page", () => {
    expect(shouldRedirectToLogin(false, false, "/login")).toBe(false);
  });

  it("returns saved path after login", () => {
    expect(getPostLoginRedirect("/customers/5")).toBe("/customers/5");
  });

  it("defaults to dashboard when no redirect target", () => {
    expect(getPostLoginRedirect(null)).toBe("/");
    expect(getPostLoginRedirect("/login")).toBe("/");
  });
});
