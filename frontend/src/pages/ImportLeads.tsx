import { useEffect, useRef, useState, type ChangeEvent } from 'react'
import { Link } from 'react-router-dom'
import { AlertTriangle, CheckCircle2, Download, FileText, Upload, Users } from 'lucide-react'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import FormCard from '../components/common/FormCard'
import PageHeader from '../components/common/PageHeader'
import StatCard from '../components/common/StatCard'
import StatusBadge from '../components/common/StatusBadge'
import { useAuth } from '../lib/auth-context'
import {
  commitLeadImport,
  downloadLeadImportTemplate,
  previewLeadImport,
  type LeadImportReport,
  type LeadImportRow,
} from '../lib/leadImport'
import { getAssignableUsers } from '../lib/leads'
import { leadSourceLabels } from '../lib/leadMeta'
import type { AssignmentTarget } from '../types'

interface ApiError {
  response?: {
    status?: number
    data?: { message?: string; errors?: Record<string, string[]> }
  }
}

const apiError = (error: unknown): ApiError => error as ApiError

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring'

/**
 * The fixed-template import (spec 16 §CSV import, spec 01 rule 7): download, preview and
 * commit. The owner rule is the only role difference — a salesrep's rows are their own,
 * while a manager/admin may leave them in the shared queue or pick a salesrep.
 */
export default function ImportLeads() {
  const { user } = useAuth()
  const canChooseOwner = user?.role !== 'salesrep'

  const fileInput = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [ownerId, setOwnerId] = useState('')
  const [owners, setOwners] = useState<AssignmentTarget[]>([])

  const [preview, setPreview] = useState<LeadImportReport | null>(null)
  const [committed, setCommitted] = useState<LeadImportReport | null>(null)

  const [downloading, setDownloading] = useState(false)
  const [previewing, setPreviewing] = useState(false)
  const [committing, setCommitting] = useState(false)

  const [message, setMessage] = useState('')
  const [fileError, setFileError] = useState('')
  const [ownerError, setOwnerError] = useState('')

  useEffect(() => {
    if (!canChooseOwner) return
    void getAssignableUsers()
      .then(setOwners)
      .catch(() => setOwners([]))
  }, [canChooseOwner])

  const resetReport = () => {
    setPreview(null)
    setCommitted(null)
    setMessage('')
    setFileError('')
    setOwnerError('')
  }

  const onFileChange = (event: ChangeEvent<HTMLInputElement>) => {
    setFile(event.target.files?.[0] ?? null)
    resetReport()
  }

  const onOwnerChange = (value: string) => {
    setOwnerId(value)
    resetReport()
  }

  const startOver = () => {
    setFile(null)
    resetReport()
    if (fileInput.current) fileInput.current.value = ''
  }

  const handleDownload = async () => {
    setDownloading(true)
    setMessage('')
    try {
      await downloadLeadImportTemplate()
    } catch {
      setMessage('Could not download the template. Please try again.')
    } finally {
      setDownloading(false)
    }
  }

  const applyError = (error: unknown, fallback: string) => {
    const response = apiError(error).response

    if (response?.status === 422) {
      const errors = response.data?.errors ?? {}
      if (errors.file?.[0]) setFileError(errors.file[0])
      if (errors.owner_id?.[0]) setOwnerError(errors.owner_id[0])
      if (!errors.file?.[0] && !errors.owner_id?.[0]) {
        setMessage(response.data?.message ?? fallback)
      }
      return
    }

    setMessage(fallback)
  }

  const handlePreview = async () => {
    if (!file) {
      setFileError('Choose a CSV file first.')
      return
    }

    setPreviewing(true)
    resetReport()
    try {
      setPreview(await previewLeadImport(file, ownerId ? Number(ownerId) : undefined))
    } catch (error) {
      applyError(error, 'Could not read that CSV. Please check the file and try again.')
    } finally {
      setPreviewing(false)
    }
  }

  const handleCommit = async () => {
    if (!file || !preview) return

    setCommitting(true)
    setMessage('')
    try {
      const report = await commitLeadImport(file, ownerId ? Number(ownerId) : undefined)
      setCommitted(report)
      setPreview(report)
    } catch (error) {
      applyError(error, 'Could not import the file. Please try again.')
    } finally {
      setCommitting(false)
    }
  }

  const columns: DataTableColumn<LeadImportRow>[] = [
    {
      key: 'line',
      header: 'Row',
      render: (row) => <span className="font-mono text-xs text-muted-foreground">{row.line}</span>,
    },
    {
      key: 'lead',
      header: 'Lead',
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-foreground">
            {row.values.company_name ?? 'Missing company'}
          </span>
          <span className="text-xs text-muted-foreground">
            {row.values.contact_name ?? 'Missing contact'}
            {row.values.contact_email ? ` · ${row.values.contact_email}` : ''}
            {!row.values.contact_email && row.values.contact_phone
              ? ` · ${row.values.contact_phone}`
              : ''}
          </span>
        </div>
      ),
    },
    {
      key: 'source',
      header: 'Source',
      render: (row) => {
        const label = row.values.source ? leadSourceLabels[row.values.source] : null

        return label ? (
          <StatusBadge tone="counts">{label}</StatusBadge>
        ) : (
          <span className="text-xs text-destructive">Missing or invalid source</span>
        )
      },
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) =>
        row.valid ? (
          <StatusBadge tone="success">
            {row.reference ? `Imported ${row.reference}` : 'Ready'}
          </StatusBadge>
        ) : (
          <StatusBadge tone="danger">Skipped</StatusBadge>
        ),
    },
    {
      key: 'issues',
      header: 'Notes',
      render: (row) => {
        const errors = Object.values(row.errors).flat()

        if (errors.length === 0 && row.duplicates.length === 0) {
          return <span className="text-xs text-muted-foreground">—</span>
        }

        return (
          <div className="max-w-md space-y-1 text-xs">
            {errors.length > 0 && (
              <ul className="list-inside list-disc text-destructive">
                {errors.map((error) => (
                  <li key={error}>{error}</li>
                ))}
              </ul>
            )}
            {row.duplicates.length > 0 && (
              <p className="text-cost">
                Possible duplicate:{' '}
                {row.duplicates.map((duplicate) => (
                  <Link
                    key={duplicate.id}
                    to={`/leads/${duplicate.id}`}
                    className="font-medium underline"
                  >
                    {duplicate.reference} — {duplicate.company_name}
                  </Link>
                ))}
              </p>
            )}
          </div>
        )
      },
    },
  ]

  const summary = preview?.summary
  const canImport = Boolean(preview && summary && summary.valid > 0 && !committed && !committing)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Import leads"
        description="Bring an existing lead list in from the fixed CSV template. Preview first, then import only the valid rows."
        actions={
          <Link
            to="/leads"
            className="text-sm font-medium text-muted-foreground hover:text-foreground"
          >
            Back to leads
          </Link>
        }
      />

      {message && <AlertItem variant="danger">{message}</AlertItem>}

      {committed && (
        <AlertItem
          variant="success"
          title={`${committed.imported ?? 0} lead(s) imported`}
        >
          <p>
            {committed.skipped
              ? `${committed.skipped} row(s) were skipped because they had validation errors.`
              : 'Every row was imported.'}{' '}
            <Link to="/leads" className="font-medium text-primary hover:underline">
              Open the Lead Workspace
            </Link>
            .
          </p>
        </AlertItem>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <FormCard
          title="1. Prepare the file"
          description="Start from the template so the column names match."
          footer={
            <button
              type="button"
              onClick={() => void handleDownload()}
              disabled={downloading}
              className="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
            >
              <Download className="h-4 w-4" aria-hidden />
              {downloading ? 'Downloading…' : 'Download template'}
            </button>
          }
        >
          <ul className="space-y-2 text-sm text-muted-foreground">
            <li>Required columns: company_name, contact_name and source.</li>
            <li>Also provide at least one of contact_email or contact_phone.</li>
            <li>Maximum 500 data rows per file.</li>
            <li>Duplicate matches are advisory; importing never merges records.</li>
          </ul>
        </FormCard>

        <FormCard
          title="2. Upload and preview"
          description="The target owner applies to every valid row in this file."
        >
          <label className="block text-sm font-medium text-foreground">
            CSV file
            <input
              ref={fileInput}
              type="file"
              accept=".csv,text/csv,text/plain"
              onChange={onFileChange}
              className="mt-1 block w-full text-sm text-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-foreground"
            />
          </label>
          {fileError && <p className="mt-1 text-xs text-destructive">{fileError}</p>}
          {file && (
            <p className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
              <FileText className="h-3.5 w-3.5" aria-hidden />
              {file.name}
            </p>
          )}

          {canChooseOwner ? (
            <label className="mt-4 block text-sm font-medium text-foreground">
              Assign imported leads to
              <select
                value={ownerId}
                onChange={(event) => onOwnerChange(event.target.value)}
                className={inputClass}
              >
                <option value="">Unassigned queue (default)</option>
                {owners.map((owner) => (
                  <option key={owner.id} value={owner.id}>
                    {owner.name}
                    {typeof owner.open_leads_count === 'number'
                      ? ` (${owner.open_leads_count} open leads)`
                      : ''}
                  </option>
                ))}
              </select>
            </label>
          ) : (
            <p className="mt-4 flex items-start gap-2 text-xs text-muted-foreground">
              <Users className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
              Your imported leads will be assigned to you.
            </p>
          )}
          {ownerError && <p className="mt-1 text-xs text-destructive">{ownerError}</p>}

          <div className="mt-4 flex flex-wrap items-center gap-3">
            <button
              type="button"
              onClick={() => void handlePreview()}
              disabled={previewing || !file}
              className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
            >
              <Upload className="h-4 w-4" aria-hidden />
              {previewing ? 'Reading…' : 'Preview file'}
            </button>
            {(file || committed) && (
              <button
                type="button"
                onClick={startOver}
                className="text-sm font-medium text-muted-foreground hover:text-foreground"
              >
                Choose another file
              </button>
            )}
          </div>
        </FormCard>

        <FormCard
          title="3. Import"
          description="Only rows that passed validation are written."
          footer={
            <button
              type="button"
              onClick={() => void handleCommit()}
              disabled={!canImport}
              className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
            >
              <CheckCircle2 className="h-4 w-4" aria-hidden />
              {committing
                ? 'Importing…'
                : summary
                  ? `Import ${summary.valid} valid lead(s)`
                  : 'Import leads'}
            </button>
          }
        >
          {summary ? (
            <dl className="space-y-2 text-sm">
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted-foreground">Rows read</dt>
                <dd className="font-medium text-foreground">{summary.rows}</dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted-foreground">Valid</dt>
                <dd className="font-medium text-people">{summary.valid}</dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted-foreground">Skipped</dt>
                <dd className="font-medium text-destructive">{summary.invalid}</dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted-foreground">Rows with possible duplicates</dt>
                <dd className="font-medium text-cost">{summary.duplicates}</dd>
              </div>
              <div className="flex items-center justify-between gap-3 border-t border-border pt-2">
                <dt className="text-muted-foreground">Owner</dt>
                <dd className="font-medium text-foreground">
                  {preview?.owner?.name ?? 'Unassigned queue'}
                </dd>
              </div>
            </dl>
          ) : (
            <p className="text-sm text-muted-foreground">
              Preview a file to see the target owner and how many rows can be imported.
            </p>
          )}
        </FormCard>
      </div>

      {preview && summary && (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard label="Rows read" value={summary.rows} domain="counts" />
            <StatCard label="Ready to import" value={summary.valid} domain="people" />
            <StatCard label="Skipped" value={summary.invalid} domain="cost" />
            <StatCard
              label="Possible duplicates"
              value={summary.duplicates}
              domain="activity"
              hint="Advisory only — nothing is merged."
            />
          </div>

          {summary.rows === 0 && (
            <AlertItem variant="warning" title="No data rows found">
              The file only has a header row. Add rows below the header and preview again.
            </AlertItem>
          )}

          {summary.valid === 0 && summary.rows > 0 && !committed && (
            <AlertItem variant="warning" title="Nothing can be imported yet">
              Every row has at least one validation error. Correct them in the CSV and preview
              again.
            </AlertItem>
          )}

          {summary.duplicates > 0 && (
            <AlertItem variant="warning" title="Possible duplicates found">
              These rows match an existing lead by company or contact channel. They can still be
              imported; the match is only a warning.
            </AlertItem>
          )}

          <div>
            <div className="mb-2 flex items-center gap-2">
              <AlertTriangle className="h-4 w-4 text-cost" aria-hidden />
              <h3 className="text-sm font-semibold text-foreground">Row preview</h3>
            </div>
            <DataTable
              caption="CSV row preview"
              columns={columns}
              rows={preview.rows}
              rowKey={(row) => String(row.line)}
              empty={
                <p className="rounded-lg border border-border bg-card p-6 text-sm text-muted-foreground">
                  No rows to show.
                </p>
              }
            />
          </div>
        </div>
      )}
    </div>
  )
}
