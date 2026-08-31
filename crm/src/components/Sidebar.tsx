import { NavLink } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";
import { canAccessRoute } from "../lib/permissions";
import { filterNavGroups, NAV_GROUPS } from "../nav";

interface SidebarProps {
  collapsed: boolean;
  onToggle: () => void;
}

export function Sidebar({ collapsed, onToggle }: SidebarProps) {
  const { permissions } = useAuth();
  const groups = filterNavGroups(NAV_GROUPS, permissions, canAccessRoute);

  return (
    <aside className={`sidebar${collapsed ? " collapsed" : ""}`}>
      <div className="sidebar-top">
        {!collapsed && <div className="brand">VPN CRM</div>}
        <button
          type="button"
          className="btn-icon sidebar-toggle"
          onClick={onToggle}
          aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"}
        >
          {collapsed ? "»" : "«"}
        </button>
      </div>
      <nav>
        {groups.map((group) => (
          <div key={group.label} className="nav-group">
            {!collapsed && <div className="nav-group-label">{group.label}</div>}
            {group.items.map((item) => (
              <NavLink
                key={item.path}
                to={item.path}
                end={item.path === "/"}
                title={collapsed ? item.label : undefined}
              >
                {collapsed ? item.label.charAt(0) : item.label}
              </NavLink>
            ))}
          </div>
        ))}
      </nav>
    </aside>
  );
}
