export function formatDate(value: string | null | undefined): string {
  if (!value) return "—";
  return new Date(value).toLocaleString();
}

export function formatCurrency(amount: string | number, currency: string): string {
  const num = typeof amount === "string" ? parseFloat(amount) : amount;
  return new Intl.NumberFormat(undefined, { style: "currency", currency }).format(num);
}

export function formatStatusLabel(status: string): string {
  return status.replace(/_/g, " ");
}
