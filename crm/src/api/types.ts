export interface ApiMeta {
  request_id?: string;
}

export interface ApiSuccess<T> {
  data: T;
  meta: ApiMeta;
}

export interface ApiErrorBody {
  code: string;
  message: string;
  request_id?: string;
}

export interface ApiErrorEnvelope {
  error: ApiErrorBody;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

export interface AdminUser {
  id: number;
  name: string;
  email: string;
  status: string;
  roles: string[];
}

export interface MeResponse {
  admin: AdminUser;
  permissions: string[];
}

export interface LoginResponse {
  token: string;
  admin: AdminUser;
}

export interface DashboardMetrics {
  customers_total: number;
  customers_active: number;
  subscriptions_active: number;
  devices_active: number;
  nodes_healthy: number;
  nodes_total: number;
  payments_total_amount: number;
}

export interface VpnPeer {
  id: number;
  peer_code: string;
  device_name?: string | null;
  platform?: string | null;
  node_name?: string | null;
  location?: string | null;
  assigned_ip: string;
  status: string;
  provisioned_at: string | null;
  revoked_at: string | null;
  latest_handshake_at?: string | null;
  rx_bytes?: number;
  tx_bytes?: number;
  last_synced_at?: string | null;
}

export interface IpPool {
  id: number;
  node_id: number;
  network: string;
  prefix_length: number;
  gateway: string;
  active: boolean;
  capacity?: number;
  allocated_count?: number;
  available_count?: number;
  node?: VpnNode;
}

export interface Customer {
  id: number;
  customer_code: string;
  name: string;
  phone: string | null;
  email: string | null;
  status: string;
  notes: string | null;
  created_at?: string;
  updated_at?: string;
  subscriptions?: Subscription[];
  devices?: Device[];
  activation_keys?: ActivationKey[];
  payments?: Payment[];
  vpn_peers?: VpnPeer[];
}

export interface Plan {
  id: number;
  name: string;
  code: string;
  description: string | null;
  price: string;
  currency: string;
  duration_days: number;
  max_devices: number;
  speed_limit_mbps: number | null;
  traffic_limit_bytes: number | null;
  active: boolean;
}

export interface Subscription {
  id: number;
  customer_id: number;
  plan_id: number;
  status: string;
  starts_at: string;
  expires_at: string;
  source: string;
  auto_renew: boolean;
  custom_max_devices: number | null;
  notes: string | null;
  customer?: Customer;
  plan?: Plan;
}

export interface ActivationKey {
  id: number;
  customer_id: number;
  key_prefix: string;
  status: string;
  max_activations: number;
  activation_count: number;
  activated_at: string | null;
  expires_at: string | null;
  last_used_at: string | null;
  revoked_at: string | null;
  customer?: Customer;
}

export interface Device {
  id: number;
  customer_id: number;
  device_uuid: string;
  platform: string;
  device_name: string | null;
  os_version: string | null;
  app_version: string | null;
  status: string;
  activated_at: string | null;
  last_seen_at: string | null;
  revoked_at: string | null;
  has_active_credential?: boolean;
  credential_issued_at?: string | null;
  credential_last_used_at?: string | null;
  customer?: Customer;
}

export interface Payment {
  id: number;
  customer_id: number;
  subscription_id: number | null;
  payment_method: string;
  external_reference: string | null;
  amount: string;
  currency: string;
  status: string;
  paid_at: string | null;
  notes: string | null;
  customer?: Customer;
  subscription?: Subscription;
}

export interface Location {
  id: number;
  country_code: string;
  country_name: string;
  city: string;
  display_name: string;
  active: boolean;
  sort_order: number;
}

export interface VpnNode {
  id: number;
  location_id: number;
  name: string;
  hostname: string;
  public_endpoint: string;
  vpn_port: number;
  public_key: string | null;
  capacity_users: number;
  health_status: string;
  lifecycle_status: string;
  maintenance_mode: boolean;
  draining?: boolean;
  adapter_type?: string;
  agent_endpoint?: string | null;
  agent_version?: string | null;
  wireguard_interface?: string;
  weight: number;
  active_peers_count?: number;
  utilization_percent?: number;
  latest_rx_bytes?: number;
  latest_tx_bytes?: number;
  last_heartbeat_at: string | null;
  last_synced_at?: string | null;
  notes: string | null;
  location?: Location;
}

export interface Role {
  id: number;
  code: string;
  name: string;
  description: string | null;
  permissions?: Permission[];
}

export interface Permission {
  id: number;
  code: string;
  name: string;
  description: string | null;
}

export interface AuditLog {
  id: number;
  actor_type: string;
  actor_id: number | null;
  action: string;
  target_type: string;
  target_id: number | null;
  before_data: Record<string, unknown> | null;
  after_data: Record<string, unknown> | null;
  source_ip: string | null;
  request_id: string | null;
  created_at: string;
}

export interface CreateCustomerPayload {
  name: string;
  phone?: string;
  email?: string;
  notes?: string;
  plan_id?: number;
  auto_renew?: boolean;
  generate_activation_key?: boolean;
  key_max_activations?: number;
  key_expires_at?: string;
}

export interface CreateCustomerResult {
  customer: Customer;
  subscription?: Subscription;
  activation_key?: { id: number; plaintext_key: string };
}
