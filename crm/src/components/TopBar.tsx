import { useAuth } from "../auth/AuthContext";

export function TopBar() {
  const { admin, logout } = useAuth();

  return (
    <header className="topbar">
      <div className="topbar-search">
        <input type="search" className="input" placeholder="Global search (customer ID, name…)" disabled />
        <span className="muted topbar-hint">Search wired in Phase 2</span>
      </div>
      <div className="topbar-user">
        <div>
          <div className="topbar-name">{admin?.name}</div>
          <div className="muted topbar-email">{admin?.email}</div>
        </div>
        <button type="button" className="btn btn-secondary btn-sm" onClick={() => void logout()}>
          Sign out
        </button>
      </div>
    </header>
  );
}
