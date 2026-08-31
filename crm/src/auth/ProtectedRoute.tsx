import { Navigate, useLocation } from "react-router-dom";
import { useAuth } from "./AuthContext";
import { shouldRedirectToLogin } from "../lib/auth";

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, loading } = useAuth();
  const location = useLocation();

  if (loading) {
    return (
      <div className="page-center">
        <div className="spinner" aria-label="Loading session" />
        <p className="muted">Checking session…</p>
      </div>
    );
  }

  if (shouldRedirectToLogin(isAuthenticated, loading, location.pathname)) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }

  return <>{children}</>;
}
