export interface ActivationKeyRevealState {
  customerCode: string;
  plaintextKey: string;
  acknowledged: boolean;
  copied: boolean;
}

interface ActivationKeyRevealProps {
  state: ActivationKeyRevealState;
  onCopy: () => void;
  onAcknowledge: () => void;
}

export function ActivationKeyReveal({ state, onCopy, onAcknowledge }: ActivationKeyRevealProps) {
  return (
    <div className="key-reveal" role="alert">
      <h3>Activation key generated</h3>
      <p className="key-warning">
        Copy this activation key now. It will not be shown again after you leave this screen.
      </p>
      <div className="key-field">
        <label htmlFor="customer-code">Customer ID</label>
        <div className="copy-row">
          <code id="customer-code">{state.customerCode}</code>
        </div>
      </div>
      <div className="key-field">
        <label htmlFor="plaintext-key">Activation Key</label>
        <div className="copy-row">
          <code id="plaintext-key">{state.plaintextKey}</code>
          <button type="button" className="btn btn-secondary" onClick={onCopy}>
            {state.copied ? "Copied" : "Copy key"}
          </button>
        </div>
      </div>
      <label className="checkbox-row">
        <input
          type="checkbox"
          checked={state.acknowledged}
          onChange={(e) => e.target.checked && onAcknowledge()}
        />
        I have saved the activation key securely
      </label>
    </div>
  );
}
