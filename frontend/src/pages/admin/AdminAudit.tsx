import { useCallback, useEffect, useState } from 'react'
import { Download } from 'lucide-react'
import PageHeader from '../../components/common/PageHeader'
import EmptyState from '../../components/common/EmptyState'
import AlertItem from '../../components/common/AlertItem'
import StatusBadge from '../../components/common/StatusBadge'
import DataTable, { type DataTableColumn } from '../../components/common/DataTable'
import { exportAuditLogs, getAuditLog, listAuditLogs, type AuditLogFilters } from '../../lib/admin'
import {
  auditActionGroups,
  auditActionLabel,
  auditResourceOptions,
  auditSeverityMeta,
} from '../../lib/auditMeta'
import { parseValidationError } from '../../lib/leads'
import { formatDateTime } from '../../lib/format'
import AdminAuditDetail from './AdminAuditDetail'
import type { AuditLogEntry, AuditSeverity } from '../../types'

const inputClass =
  'w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

const emptyFilters: AuditLogFilters = {}

/**
 * The immutable audit book (module 08 slice S5, spec 19).
 *
 * Filters for actor, event, resource, severity, date range and correlation id; a detail panel
 * with the redacted before/after snapshots and request metadata; and a bounded CSV export that
 * is itself audited. The server scopes security-sensitive events to a superadmin, so the page
 * never has to hide a row it was allowed to fetch.
 */
export default function AdminAudit() {
  const [rows, setRows] = useState<AuditLogEntry[]>([])
  const [selected, setSelected] = useState<AuditLogEntry | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState('')

  const [actor, setActor] = useState('')
  const [action, setAction] = useState('')
  const [resource, setResource] = useState('')
  const [severity, setSeverity] = useState<AuditSeverity | ''>('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [requestId, setRequestId] = useState('')

  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState('')
  const [notice, setNotice] = useState('')

  const load = useCallback(async (targetPage: number, filters: AuditLogFilters) => {
    setLoading(true)
    setError('')
    try {
      const response = await listAuditLogs({ ...filters, page: targetPage })
      setRows(response.data)
      setPage(response.meta.current_page)
      setLastPage(response.meta.last_page)
      setTotal(response.meta.total)
    } catch {
      setError('Could not load the audit book.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load(1, emptyFilters)
  }, [load])

  const filters: AuditLogFilters = {
    actor: actor.trim(),
    action,
    resource,
    severity,
    from,
    to,
    request_id: requestId.trim(),
  }

  const openDetail = (row: AuditLogEntry) => {
    setSelected(row)
    setDetailError('')
    setDetailLoading(true)
    void getAuditLog(row.id)
      .then((entry) => setSelected(entry))
      .catch(() => setDetailError('Could not load the audit detail.'))
      .finally(() => setDetailLoading(false))
  }

  const clear = () => {
    setActor('')
    setAction('')
    setResource('')
    setSeverity('')
    setFrom('')
    setTo('')
    setRequestId('')
    void load(1, emptyFilters)
  }

  const handleExport = () => {
    if (
      !window.confirm(
        'Export the filtered audit rows as CSV? The export is bounded and is itself recorded in the audit trail.',
      )
    ) {
      return
    }

    setExporting(true)
    setExportError('')
    setNotice('')

    void exportAuditLogs(filters)
      .then((blob) => {
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `audit-log-${new Date().toISOString().slice(0, 10)}.csv`
        document.body.appendChild(link)
        link.click()
        link.remove()
        URL.revokeObjectURL(url)
        setNotice('The export was generated and recorded in the audit trail.')
      })
      .catch((caught) => {
        const validation = parseValidationError(caught)
        setExportError(
          validation?.errors.export?.[0] ??
            validation?.message ??
            'Could not export the audit log. Narrow the filters and try again.',
        )
      })
      .finally(() => setExporting(false))
  }

  const columns: DataTableColumn<AuditLogEntry>[] = [
    {
      key: 'created_at',
      header: 'When',
      className: 'whitespace-nowrap text-muted-foreground',
      render: (row) => formatDateTime(row.created_at),
    },
    {
      key: 'action',
      header: 'Event',
      render: (row) => (
        <div>
          <button
            type="button"
            onClick={() => openDetail(row)}
            className="text-left font-medium text-foreground hover:text-primary hover:underline"
          >
            {auditActionLabel(row.action)}
          </button>
          <p className="font-mono text-xs text-muted-foreground">{row.action}</p>
        </div>
      ),
    },
    {
      key: 'severity',
      header: 'Severity',
      render: (row) => (
        <StatusBadge tone={auditSeverityMeta[row.severity].tone}>
          {auditSeverityMeta[row.severity].label}
        </StatusBadge>
      ),
    },
    {
      key: 'actor',
      header: 'Actor',
      className: 'text-muted-foreground',
      render: (row) =>
        row.actor ? (
          <div>
            <p className="text-foreground">{row.actor.name}</p>
            <p className="text-xs">{row.actor.email}</p>
          </div>
        ) : (
          'System'
        ),
    },
    {
      key: 'subject',
      header: 'Subject',
      className: 'text-muted-foreground',
      render: (row) =>
        row.subject ? (
          <div>
            <p className="text-foreground">{row.subject.type ?? 'Record'}</p>
            <p className="font-mono text-xs">{row.subject.name ?? row.subject.id ?? '—'}</p>
          </div>
        ) : (
          '—'
        ),
    },
    {
      key: 'request',
      header: 'Correlation',
      className: 'font-mono text-xs text-muted-foreground',
      render: (row) => row.request.id.slice(0, 12),
    },
  ]

  return (
    <div>
      <PageHeader
        title="Audit log"
        description="The immutable administrative history. Rows are append-only; secrets are removed and contact PII is masked."
        actions={
          <button
            type="button"
            onClick={handleExport}
            disabled={exporting}
            className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
          >
            <Download className="h-4 w-4" aria-hidden />
            {exporting ? 'Exporting…' : 'Export CSV'}
          </button>
        }
      />

      {error && (
        <div className="mb-4">
          <AlertItem variant="danger">{error}</AlertItem>
        </div>
      )}
      {exportError && (
        <div className="mb-4">
          <AlertItem variant="danger">{exportError}</AlertItem>
        </div>
      )}
      {notice && (
        <div className="mb-4">
          <AlertItem variant="success">{notice}</AlertItem>
        </div>
      )}

      <form
        className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4"
        onSubmit={(event) => {
          event.preventDefault()
          void load(1, filters)
        }}
      >
        <input
          type="search"
          value={actor}
          onChange={(event) => setActor(event.target.value)}
          placeholder="Actor name, email or ULID"
          aria-label="Filter by actor"
          className={inputClass}
        />

        <select
          value={action}
          onChange={(event) => setAction(event.target.value)}
          aria-label="Filter by event"
          className={inputClass}
        >
          <option value="">All events</option>
          {auditActionGroups().map((group) => (
            <optgroup key={group.group} label={group.group}>
              {group.actions.map((value) => (
                <option key={value} value={value}>
                  {auditActionLabel(value)}
                </option>
              ))}
            </optgroup>
          ))}
        </select>

        <select
          value={resource}
          onChange={(event) => setResource(event.target.value)}
          aria-label="Filter by resource"
          className={inputClass}
        >
          <option value="">All resources</option>
          {auditResourceOptions.map((value) => (
            <option key={value} value={value}>
              {value}
            </option>
          ))}
        </select>

        <select
          value={severity}
          onChange={(event) => setSeverity(event.target.value as AuditSeverity | '')}
          aria-label="Filter by severity"
          className={inputClass}
        >
          <option value="">All severities</option>
          {(Object.keys(auditSeverityMeta) as AuditSeverity[]).map((value) => (
            <option key={value} value={value}>
              {auditSeverityMeta[value].label}
            </option>
          ))}
        </select>

        <label className="block text-xs font-medium text-muted-foreground">
          From
          <input
            type="date"
            value={from}
            onChange={(event) => setFrom(event.target.value)}
            aria-label="From date"
            className={`mt-1 ${inputClass}`}
          />
        </label>

        <label className="block text-xs font-medium text-muted-foreground">
          To
          <input
            type="date"
            value={to}
            onChange={(event) => setTo(event.target.value)}
            aria-label="To date"
            className={`mt-1 ${inputClass}`}
          />
        </label>

        <input
          type="search"
          value={requestId}
          onChange={(event) => setRequestId(event.target.value)}
          placeholder="Correlation id"
          aria-label="Filter by correlation id"
          className={inputClass}
        />

        <div className="flex items-center gap-2">
          <button
            type="submit"
            className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
          >
            Filter
          </button>
          <button
            type="button"
            onClick={clear}
            className="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground hover:text-foreground"
          >
            Clear
          </button>
        </div>
      </form>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div className={selected ? 'xl:col-span-2' : 'xl:col-span-3'}>
          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(row) => row.id}
            loading={loading}
            caption="Audit events"
            pagination={{
              currentPage: page,
              lastPage,
              total,
              onPageChange: (next) => void load(next, filters),
            }}
            empty={
              <EmptyState
                title="No audit events match"
                description="Adjust the filters, or clear them to read the full book."
              />
            }
          />
        </div>

        {selected && (
          <div className="xl:col-span-1">
            <AdminAuditDetail
              entry={selected}
              loading={detailLoading}
              error={detailError}
              onClose={() => setSelected(null)}
            />
          </div>
        )}
      </div>
    </div>
  )
}
