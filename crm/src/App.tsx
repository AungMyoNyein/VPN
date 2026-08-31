import { Navigate, Route, Routes } from "react-router-dom";
import { AuthProvider } from "./auth/AuthContext";
import { ProtectedRoute } from "./auth/ProtectedRoute";
import { AppLayout } from "./layout/AppLayout";
import { LoginPage } from "./pages/LoginPage";
import { DashboardPage } from "./pages/DashboardPage";
import { CustomersPage } from "./pages/customers/CustomersPage";
import { CustomerCreatePage } from "./pages/customers/CustomerCreatePage";
import { CustomerDetailPage } from "./pages/customers/CustomerDetailPage";
import { PlansPage } from "./pages/plans/PlansPage";
import { SubscriptionsPage } from "./pages/subscriptions/SubscriptionsPage";
import { ActivationKeysPage } from "./pages/activation-keys/ActivationKeysPage";
import { PaymentsPage } from "./pages/payments/PaymentsPage";
import { DevicesPage } from "./pages/devices/DevicesPage";
import { LocationsPage } from "./pages/locations/LocationsPage";
import { VpnNodesPage } from "./pages/vpn-nodes/VpnNodesPage";
import { IpPoolsPage } from "./pages/ip-pools/IpPoolsPage";
import { AdminUsersPage } from "./pages/admin-users/AdminUsersPage";
import { RolesPage } from "./pages/roles/RolesPage";
import { AuditLogsPage } from "./pages/audit-logs/AuditLogsPage";
import { SettingsPage } from "./pages/settings/SettingsPage";

export function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route
          element={
            <ProtectedRoute>
              <AppLayout />
            </ProtectedRoute>
          }
        >
          <Route path="/" element={<DashboardPage />} />
          <Route path="/customers" element={<CustomersPage />} />
          <Route path="/customers/new" element={<CustomerCreatePage />} />
          <Route path="/customers/:id" element={<CustomerDetailPage />} />
          <Route path="/plans" element={<PlansPage />} />
          <Route path="/subscriptions" element={<SubscriptionsPage />} />
          <Route path="/activation-keys" element={<ActivationKeysPage />} />
          <Route path="/payments" element={<PaymentsPage />} />
          <Route path="/devices" element={<DevicesPage />} />
          <Route path="/locations" element={<LocationsPage />} />
          <Route path="/vpn-nodes" element={<VpnNodesPage />} />
          <Route path="/ip-pools" element={<IpPoolsPage />} />
          <Route path="/admin-users" element={<AdminUsersPage />} />
          <Route path="/roles" element={<RolesPage />} />
          <Route path="/audit-logs" element={<AuditLogsPage />} />
          <Route path="/settings" element={<SettingsPage />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </AuthProvider>
  );
}
