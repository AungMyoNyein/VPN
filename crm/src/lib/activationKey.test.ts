import { describe, expect, it } from "vitest";
import {
  canDismissKeyReveal,
  createKeyRevealState,
  markKeyAcknowledged,
  markKeyCopied,
  shouldShowKeyWarning,
} from "./activationKey";

describe("activation key display-once behavior", () => {
  it("creates reveal state with customer code and plaintext key", () => {
    const state = createKeyRevealState("CUST-000001", "VPN-ABCD-EFGH-IJKL");
    expect(state.customerCode).toBe("CUST-000001");
    expect(state.plaintextKey).toBe("VPN-ABCD-EFGH-IJKL");
    expect(state.acknowledged).toBe(false);
  });

  it("shows warning until acknowledged", () => {
    const state = createKeyRevealState("CUST-000001", "VPN-ABCD-EFGH-IJKL");
    expect(shouldShowKeyWarning(state)).toBe(true);
    expect(canDismissKeyReveal(state)).toBe(false);
    const acked = markKeyAcknowledged(state);
    expect(shouldShowKeyWarning(acked)).toBe(false);
    expect(canDismissKeyReveal(acked)).toBe(true);
  });

  it("tracks copy action", () => {
    const state = createKeyRevealState("CUST-000001", "VPN-ABCD-EFGH-IJKL");
    const copied = markKeyCopied(state);
    expect(copied.copied).toBe(true);
    expect(copied.acknowledged).toBe(false);
  });

  it("returns false for null reveal state", () => {
    expect(shouldShowKeyWarning(null)).toBe(false);
  });
});
