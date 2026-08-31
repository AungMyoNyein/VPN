export type BadgeVariant = "success" | "warning" | "danger" | "neutral" | "info";

const STATUS_VARIANTS: Record<string, BadgeVariant> = {
  ACTIVE: "success",
  PAID: "success",
  HEALTHY: "success",
  ONLINE: "success",
  SUSPENDED: "warning",
  PENDING: "warning",
  DRAINING: "warning",
  EXPIRED: "danger",
  REVOKED: "danger",
  BLOCKED: "danger",
  DISABLED: "danger",
  UNHEALTHY: "danger",
  OFFLINE: "danger",
  ERROR: "danger",
};

export function getBadgeVariant(status: string): BadgeVariant {
  return STATUS_VARIANTS[status.toUpperCase()] ?? "neutral";
}

interface BadgeProps {
  status: string;
  label?: string;
}

export function Badge({ status, label }: BadgeProps) {
  const variant = getBadgeVariant(status);
  const text = label ?? status.replace(/_/g, " ");

  return (
    <span className={`badge badge-${variant}`} aria-label={`Status: ${text}`}>
      {text}
    </span>
  );
}
