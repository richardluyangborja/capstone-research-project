import type { ReactNode } from 'react'

export interface DataTableColumn<T> {
  key: string
  header: ReactNode
  render: (row: T) => ReactNode
  /** Applied to the cell (`td`). */
  className?: string
  /** Applied to the heading (`th`). */
  headerClassName?: string
}

export interface DataTableSelection<T> {
  selected: string[]
  onToggle: (key: string) => void
  onToggleAll: (keys: string[], checked: boolean) => void
  onClear?: () => void
  /** Rows that cannot be selected (e.g. ones the user may not act on). */
  isRowSelectable?: (row: T) => boolean
}

export interface DataTablePagination {
  currentPage: number
  lastPage: number
  total: number
  onPageChange: (page: number) => void
}

interface DataTableProps<T> {
  columns: DataTableColumn<T>[]
  rows: T[]
  rowKey: (row: T) => string
  loading?: boolean
  loadingLabel?: string
  /** Rendered instead of the table when there are no rows. */
  empty?: ReactNode
  selection?: DataTableSelection<T>
  /** Controls shown in the selection bar while rows are selected. */
  bulkActions?: ReactNode
  pagination?: DataTablePagination
  caption?: string
}

/**
 * Shared list/table (design system rule) — headings, optional multi-select with a bulk
 * action bar, loading and empty states, and simple pagination. Purely presentational:
 * the owning page keeps the data and filters, which keeps each list's behaviour explicit.
 */
export default function DataTable<T>({
  columns,
  rows,
  rowKey,
  loading = false,
  loadingLabel = 'Loading…',
  empty,
  selection,
  bulkActions,
  pagination,
  caption,
}: DataTableProps<T>) {
  if (!loading && rows.length === 0 && empty) {
    return <>{empty}</>
  }

  const selectableKeys = selection
    ? rows.filter((row) => selection.isRowSelectable?.(row) ?? true).map(rowKey)
    : []

  const allSelected =
    selectableKeys.length > 0 && selectableKeys.every((key) => selection!.selected.includes(key))

  const columnCount = columns.length + (selection ? 1 : 0)
  const cellClass = (extra?: string) => `px-4 py-3 ${extra ?? ''}`
  const headClass = (extra?: string) => `px-4 py-3 font-medium ${extra ?? ''}`

  return (
    <div>
      {selection && selection.selected.length > 0 && (
        <div className="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-border bg-secondary px-3 py-2 text-sm">
          <span className="font-medium text-secondary-foreground">
            {selection.selected.length} selected
          </span>
          {bulkActions}
          {selection.onClear && (
            <button
              type="button"
              onClick={selection.onClear}
              className="rounded-md px-2 py-1.5 text-sm text-muted-foreground hover:text-foreground"
            >
              Clear
            </button>
          )}
        </div>
      )}

      <div className="overflow-x-auto rounded-lg border border-border bg-card">
        <table className="w-full min-w-max text-left text-sm">
          {caption && <caption className="sr-only">{caption}</caption>}
          <thead className="border-b border-border text-xs uppercase text-muted-foreground">
            <tr>
              {selection && (
                <th className={headClass('w-10')}>
                  <input
                    type="checkbox"
                    aria-label="Select all"
                    checked={allSelected}
                    onChange={() => selection.onToggleAll(selectableKeys, !allSelected)}
                    className="h-4 w-4 rounded border-input"
                  />
                </th>
              )}
              {columns.map((column) => (
                <th key={column.key} className={headClass(column.headerClassName)}>
                  {column.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td colSpan={columnCount} className="px-4 py-6 text-center text-muted-foreground">
                  {loadingLabel}
                </td>
              </tr>
            )}

            {!loading &&
              rows.map((row) => {
                const key = rowKey(row)
                return (
                  <tr key={key} className="border-b border-border last:border-0">
                    {selection && (
                      <td className="px-4 py-3">
                        <input
                          type="checkbox"
                          aria-label={`Select ${key}`}
                          checked={selection.selected.includes(key)}
                          onChange={() => selection.onToggle(key)}
                          className="h-4 w-4 rounded border-input"
                        />
                      </td>
                    )}
                    {columns.map((column) => (
                      <td key={column.key} className={cellClass(column.className)}>
                        {column.render(row)}
                      </td>
                    ))}
                  </tr>
                )
              })}
          </tbody>
        </table>
      </div>

      {pagination && pagination.lastPage > 1 && (
        <div className="mt-3 flex items-center justify-between text-sm text-muted-foreground">
          <span>
            Page {pagination.currentPage} of {pagination.lastPage} · {pagination.total} total
          </span>
          <div className="flex items-center gap-2">
            <button
              type="button"
              disabled={pagination.currentPage <= 1}
              onClick={() => pagination.onPageChange(pagination.currentPage - 1)}
              className="rounded-md border border-border px-3 py-1.5 font-medium text-foreground hover:bg-muted disabled:opacity-50"
            >
              Previous
            </button>
            <button
              type="button"
              disabled={pagination.currentPage >= pagination.lastPage}
              onClick={() => pagination.onPageChange(pagination.currentPage + 1)}
              className="rounded-md border border-border px-3 py-1.5 font-medium text-foreground hover:bg-muted disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
