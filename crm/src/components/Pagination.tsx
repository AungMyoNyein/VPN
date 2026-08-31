interface PaginationProps {
  page: number;
  lastPage: number;
  total: number;
  onPageChange: (page: number) => void;
}

export function Pagination({ page, lastPage, total, onPageChange }: PaginationProps) {
  if (lastPage <= 1) return null;

  return (
    <div className="pagination" aria-label="Pagination">
      <span className="muted">
        Page {page} of {lastPage} ({total} total)
      </span>
      <div className="pagination-actions">
        <button
          type="button"
          className="btn btn-secondary btn-sm"
          disabled={page <= 1}
          onClick={() => onPageChange(page - 1)}
        >
          Previous
        </button>
        <button
          type="button"
          className="btn btn-secondary btn-sm"
          disabled={page >= lastPage}
          onClick={() => onPageChange(page + 1)}
        >
          Next
        </button>
      </div>
    </div>
  );
}
