import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import { authApi } from "../api/endpoints";
import { ApiClientError, getStoredToken, setStoredToken } from "../api/client";
import type { AdminUser } from "../api/types";

interface AuthState {
  admin: AdminUser | null;
  permissions: string[];
  token: string | null;
  loading: boolean;
  error: string | null;
}

interface AuthContextValue extends AuthState {
  isAuthenticated: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refreshMe: () => Promise<void>;
  hasPermission: (code: string) => boolean;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({
    admin: null,
    permissions: [],
    token: getStoredToken(),
    loading: true,
    error: null,
  });

  const refreshMe = useCallback(async () => {
    const token = getStoredToken();
    if (!token) {
      setState((s) => ({ ...s, admin: null, permissions: [], token: null, loading: false }));
      return;
    }
    try {
      const data = await authApi.me();
      setState({
        admin: data.admin,
        permissions: data.permissions,
        token,
        loading: false,
        error: null,
      });
    } catch (err) {
      setStoredToken(null);
      setState({
        admin: null,
        permissions: [],
        token: null,
        loading: false,
        error: err instanceof ApiClientError ? err.message : "Session expired.",
      });
    }
  }, []);

  useEffect(() => {
    void refreshMe();
  }, [refreshMe]);

  const login = useCallback(async (email: string, password: string) => {
    const data = await authApi.login(email, password);
    setStoredToken(data.token);
    setState({
      admin: data.admin,
      permissions: [],
      token: data.token,
      loading: true,
      error: null,
    });
    const me = await authApi.me();
    setState({
      admin: me.admin,
      permissions: me.permissions,
      token: data.token,
      loading: false,
      error: null,
    });
  }, []);

  const logout = useCallback(async () => {
    try {
      await authApi.logout();
    } catch {
      // ignore logout errors
    }
    setStoredToken(null);
    setState({
      admin: null,
      permissions: [],
      token: null,
      loading: false,
      error: null,
    });
  }, []);

  const hasPermission = useCallback(
    (code: string) => state.permissions.includes(code),
    [state.permissions],
  );

  const value = useMemo<AuthContextValue>(
    () => ({
      ...state,
      isAuthenticated: Boolean(state.token && state.admin),
      login,
      logout,
      refreshMe,
      hasPermission,
    }),
    [state, login, logout, refreshMe, hasPermission],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
