import { apiRequest } from "./client";
import type {
  ActivationKey,
  AdminUser,
  AuditLog,
  CreateCustomerPayload,
  CreateCustomerResult,
  Customer,
  DashboardMetrics,
  Device,
  IpPool,
  Location,
  LoginResponse,
  MeResponse,
  Paginated,
  Payment,
  Plan,
  Role,
  Subscription,
  VpnNode,
} from "./types";

export const authApi = {
  login: (email: string, password: string) =>
    apiRequest<LoginResponse>("/auth/login", { method: "POST", body: { email, password }, token: null }),
  logout: () => apiRequest<{ message: string }>("/auth/logout", { method: "POST" }),
  me: () => apiRequest<MeResponse>("/auth/me"),
};

export const dashboardApi = {
  metrics: () => apiRequest<{ metrics: DashboardMetrics }>("/dashboard"),
};

export const customersApi = {
  list: (params: Record<string, string | number | undefined> = {}) => {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== "") qs.set(k, String(v));
    });
    const q = qs.toString();
    return apiRequest<Paginated<Customer>>(`/customers${q ? `?${q}` : ""}`);
  },
  get: (id: number) => apiRequest<{ customer: Customer }>(`/customers/${id}`),
  create: (payload: CreateCustomerPayload) =>
    apiRequest<CreateCustomerResult>("/customers", { method: "POST", body: payload }),
  update: (id: number, payload: Partial<CreateCustomerPayload>) =>
    apiRequest<{ customer: Customer }>(`/customers/${id}`, { method: "PUT", body: payload }),
  changeStatus: (id: number, status: string) =>
    apiRequest<{ customer: Customer }>(`/customers/${id}/status`, {
      method: "PATCH",
      body: { status },
    }),
  renew: (id: number, payload: Record<string, unknown>) =>
    apiRequest<{ subscription: Subscription }>(`/customers/${id}/renew`, {
      method: "POST",
      body: payload,
    }),
  generateKey: (id: number, payload: Record<string, unknown> = {}) =>
    apiRequest<{ activation_key: ActivationKey; plaintext_key: string }>(
      `/customers/${id}/activation-keys`,
      { method: "POST", body: payload },
    ),
  addPayment: (id: number, payload: Record<string, unknown>) =>
    apiRequest<{ payment: Payment }>(`/customers/${id}/payments`, {
      method: "POST",
      body: payload,
    }),
};

export const plansApi = {
  list: (active?: boolean) => {
    const q = active !== undefined ? `?active=${active}` : "";
    return apiRequest<{ plans: Plan[] }>(`/plans${q}`);
  },
  create: (payload: Record<string, unknown>) =>
    apiRequest<{ plan: Plan }>("/plans", { method: "POST", body: payload }),
  update: (id: number, payload: Record<string, unknown>) =>
    apiRequest<{ plan: Plan }>(`/plans/${id}`, { method: "PUT", body: payload }),
  remove: (id: number) =>
    apiRequest<{ message: string }>(`/plans/${id}`, { method: "DELETE" }),
};

export const subscriptionsApi = {
  list: (params: Record<string, string | number | undefined> = {}) => {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== "") qs.set(k, String(v));
    });
    const q = qs.toString();
    return apiRequest<Paginated<Subscription>>(`/subscriptions${q ? `?${q}` : ""}`);
  },
};

export const activationKeysApi = {
  list: (params: Record<string, string | number | undefined> = {}) => {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== "") qs.set(k, String(v));
    });
    const q = qs.toString();
    return apiRequest<Paginated<ActivationKey>>(`/activation-keys${q ? `?${q}` : ""}`);
  },
  revoke: (id: number) =>
    apiRequest<{ activation_key: ActivationKey }>(`/activation-keys/${id}/revoke`, {
      method: "POST",
    }),
  suspend: (id: number) =>
    apiRequest<{ activation_key: ActivationKey }>(`/activation-keys/${id}/suspend`, {
      method: "POST",
    }),
};

export const devicesApi = {
  list: (params: Record<string, string | number | undefined> = {}) => {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== "") qs.set(k, String(v));
    });
    const q = qs.toString();
    return apiRequest<Paginated<Device>>(`/devices${q ? `?${q}` : ""}`);
  },
  revoke: (id: number) =>
    apiRequest<{ device: Device }>(`/devices/${id}/revoke`, { method: "POST" }),
  block: (id: number) =>
    apiRequest<{ device: Device }>(`/devices/${id}/block`, { method: "POST" }),
  resetBinding: (id: number) =>
    apiRequest<{ device: Device }>(`/devices/${id}/reset-binding`, { method: "POST" }),
};

export const paymentsApi = {
  list: (params: Record<string, string | number | undefined> = {}) => {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== "") qs.set(k, String(v));
    });
    const q = qs.toString();
    return apiRequest<Paginated<Payment>>(`/payments${q ? `?${q}` : ""}`);
  },
  create: (payload: Record<string, unknown>) =>
    apiRequest<{ payment: Payment }>("/payments", { method: "POST", body: payload }),
};

export const locationsApi = {
  list: () => apiRequest<{ locations: Location[] }>("/locations"),
  create: (payload: Record<string, unknown>) =>
    apiRequest<{ location: Location }>("/locations", { method: "POST", body: payload }),
  update: (id: number, payload: Record<string, unknown>) =>
    apiRequest<{ location: Location }>(`/locations/${id}`, { method: "PUT", body: payload }),
  remove: (id: number) =>
    apiRequest<{ message: string }>(`/locations/${id}`, { method: "DELETE" }),
};

export const vpnNodesApi = {
  list: (locationId?: number) => {
    const q = locationId ? `?location_id=${locationId}` : "";
    return apiRequest<{ vpn_nodes: VpnNode[] }>(`/vpn-nodes${q}`);
  },
  create: (payload: Record<string, unknown>) =>
    apiRequest<{ vpn_node: VpnNode }>("/vpn-nodes", { method: "POST", body: payload }),
  update: (id: number, payload: Record<string, unknown>) =>
    apiRequest<{ vpn_node: VpnNode }>(`/vpn-nodes/${id}`, { method: "PUT", body: payload }),
  updateLifecycle: (id: number, payload: Record<string, unknown>) =>
    apiRequest<{ vpn_node: VpnNode }>(`/vpn-nodes/${id}/lifecycle`, {
      method: "PATCH",
      body: payload,
    }),
  toggleDrain: (id: number, draining?: boolean) =>
    apiRequest<{ vpn_node: VpnNode }>(`/vpn-nodes/${id}/drain`, {
      method: "POST",
      body: { draining },
    }),
  toggleMaintenance: (id: number, maintenance_mode?: boolean) =>
    apiRequest<{ vpn_node: VpnNode }>(`/vpn-nodes/${id}/maintenance`, {
      method: "POST",
      body: { maintenance_mode },
    }),
  remove: (id: number) =>
    apiRequest<{ message: string }>(`/vpn-nodes/${id}`, { method: "DELETE" }),
};

export const ipPoolsApi = {
  list: (nodeId?: number) => {
    const q = nodeId ? `?node_id=${nodeId}` : "";
    return apiRequest<{ ip_pools: IpPool[] }>(`/ip-pools${q}`);
  },
  create: (payload: { node_id: number; network: string; prefix_length: number; gateway: string }) =>
    apiRequest<{ ip_pool: IpPool }>("/ip-pools", { method: "POST", body: payload }),
  toggleActive: (id: number) =>
    apiRequest<{ ip_pool: IpPool }>(`/ip-pools/${id}/toggle`, { method: "POST" }),
};

export const adminUsersApi = {
  list: () => apiRequest<{ admin_users: AdminUser[] }>("/admin-users"),
};

export const rolesApi = {
  list: () => apiRequest<{ roles: Role[] }>("/roles"),
};

export const auditLogsApi = {
  list: (params: Record<string, string | number | undefined> = {}) => {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== "") qs.set(k, String(v));
    });
    const q = qs.toString();
    return apiRequest<Paginated<AuditLog>>(`/audit-logs${q ? `?${q}` : ""}`);
  },
};
