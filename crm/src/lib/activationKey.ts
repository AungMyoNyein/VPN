import type { ActivationKeyRevealState } from "../components/ActivationKeyReveal";

export function createKeyRevealState(
  customerCode: string,
  plaintextKey: string,
): ActivationKeyRevealState {
  return {
    customerCode,
    plaintextKey,
    acknowledged: false,
    copied: false,
  };
}

export function canDismissKeyReveal(state: ActivationKeyRevealState): boolean {
  return state.acknowledged;
}

export function markKeyCopied(state: ActivationKeyRevealState): ActivationKeyRevealState {
  return { ...state, copied: true };
}

export function markKeyAcknowledged(state: ActivationKeyRevealState): ActivationKeyRevealState {
  return { ...state, acknowledged: true };
}

export function shouldShowKeyWarning(state: ActivationKeyRevealState | null): boolean {
  return state !== null && !state.acknowledged;
}
