import { useCallback, useEffect, useState } from "react";
import { ApiClientError } from "../api/client";
import { EmptyState, ErrorState, LoadingState } from "./StateBox";

interface UseListQueryOptions<T> {
  fetcher: () => Promise<T>;
  deps?: unknown[];
}

export function useListQuery<T>({ fetcher, deps = [] }: UseListQueryOptions<T>) {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const reload = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await fetcher();
      setData(result);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load data.");
    } finally {
      setLoading(false);
    }
  }, [fetcher]);

  useEffect(() => {
    void reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return { data, loading, error, reload };
}

interface ListShellProps {
  loading: boolean;
  error: string | null;
  empty: boolean;
  emptyTitle: string;
  emptyDescription?: string;
  onRetry?: () => void;
  children: React.ReactNode;
}

export function ListShell({
  loading,
  error,
  empty,
  emptyTitle,
  emptyDescription,
  onRetry,
  children,
}: ListShellProps) {
  if (loading) return <LoadingState />;
  if (error) return <ErrorState message={error} onRetry={onRetry} />;
  if (empty) return <EmptyState title={emptyTitle} description={emptyDescription} />;
  return <>{children}</>;
}

interface SearchBarProps {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  filters?: React.ReactNode;
}

export function SearchBar({ value, onChange, placeholder = "Search…", filters }: SearchBarProps) {
  return (
    <div className="toolbar">
      <input
        type="search"
        className="input search-input"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        aria-label="Search"
      />
      {filters}
    </div>
  );
}
