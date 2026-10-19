import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FilePlus2, RotateCcw, Search, Trash2 } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import {
  deleteCommunicationTemplate,
  listCommunicationTemplates,
  restoreCommunicationTemplate,
  type CommunicationTemplateFilters,
  type TemplateState,
} from '../lib/communicationTemplates'
import { communicationChannelMeta } from '../lib/communicationMeta'
import { formatDateTime } from '../lib/format'
import { useAuth } from '../lib/auth-context'
import type { CommunicationChannel, CommunicationTemplate } from '../types'

const stateMeta: Record<
  'active' | 'inactive' | 'retired',
  { label: string; tone: 'success' | 'neutral' | 'danger' }
> = {
  active: { label: 'Active', tone: 'success' },
  inactive: { label: 'Inactive', tone: 'neutral' },
  retired: { label: 'Retired', tone: 'danger' },
}

const stateOf = (template: CommunicationTemplate): 'active' | 'inactive' | 'retired' =>
  template.deleted_at !== null ? 'retired' : template.is_active ? 'active' : 'inactive'

// Module 03 §UI (slice S5) — the house's wording. Every role reads it (the composer picks from
// it); manager+ authors, edits and retires. A template is only ever a prefill, so retiring one
// never touches a message that was already written with it.
export default function CommunicationTemplates() {
  const { user } = useAuth()
  const isManagerOrAbove = user?.role !== 'salesrep'

  const [templates, setTemplates] = useState<CommunicationTemplate[]>([])
  const [search, setSearch] = useState('')
  const [channel, setChannel] = useState('')
  const [state, setState] = useState<TemplateState>('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const load = useCallback(
    async (targetPage: number) => {
      setLoading(true)
      setError('')
      try {
        const filters: CommunicationTemplateFilters = {
          search: search || undefined,
          channel: channel || undefined,
          state,
          page: targetPage,
        }
        const response = await listCommunicationTemplates(filters)
        setTemplates(response.data)
        setPage(response.meta.current_page)
        setLastPage(response.meta.last_page)
        setTotal(response.meta.total)
      } catch {
        setError('Could not load the templates. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [search, channel, state],
  )

  useEffect(() => {
    void load(1)
  }, [load])

  const onRetire = async (template: CommunicationTemplate) => {
    setSaving(template.id)
    setError('')
    setNotice('')
    try {
      await deleteCommunicationTemplate(template.id)
      setNotice(`“${template.name}” retired — it no longer appears in the composer.`)
      await load(page)
    } catch {
      setError('Could not retire that template.')
    } finally {
      setSaving('')
    }
  }

  const onRestore = async (template: CommunicationTemplate) => {
    setSaving(template.id)
    setError('')
    setNotice('')
    try {
      await restoreCommunicationTemplate(template.id)
      setNotice(`“${template.name}” is back.`)
      await load(page)
    } catch {
      // The API refuses when a live template has taken the name since (spec 11 §S5).
      setError(
        'Could not restore that template — another template may already use its name. Rename the live one first.',
      )
    } finally {
      setSaving('')
    }
  }

  const columns: DataTableColumn<CommunicationTemplate>[] = [
    {
      key: 'name',
      header: 'Template',
      render: (template) => {
        const templateState = stateOf(template)

        return (
          <div className="flex flex-wrap items-center gap-2">
            {template.can_update ? (
              <Link
                to={`/communications/templates/${template.id}`}
                className="font-medium text-counts"
              >
                {template.name}
              </Link>
            ) : (
              <span className="font-medium text-foreground">{template.name}</span>
            )}
            <StatusBadge tone={stateMeta[templateState].tone}>
              {stateMeta[templateState].label}
            </StatusBadge>
          </div>
        )
      },
    },
    {
      key: 'channel',
      header: 'Channel',
      render: (template) => (
        <StatusBadge tone={communicationChannelMeta[template.channel].tone}>
          {communicationChannelMeta[template.channel].label}
        </StatusBadge>
      ),
    },
    {
      key: 'subject',
      header: 'Title line',
      className: 'text-muted-foreground',
      render: (template) => <span className="line-clamp-1">{template.subject}</span>,
    },
    {
      key: 'author',
      header: 'Author',
      className: 'text-muted-foreground',
      render: (template) => template.author?.name ?? '—',
    },
    {
      key: 'updated_at',
      header: 'Updated',
      className: 'whitespace-nowrap text-muted-foreground',
      render: (template) => formatDateTime(template.updated_at),
    },
    {
      key: 'actions',
      header: '',
      render: (template) => (
        <div className="flex items-center justify-end gap-3">
          {stateOf(template) === 'retired' && template.can_restore && (
            <button
              type="button"
              onClick={() => void onRestore(template)}
              disabled={saving === template.id}
              className="inline-flex items-center gap-1 text-xs font-medium text-counts disabled:opacity-60"
            >
              <RotateCcw className="h-3.5 w-3.5" aria-hidden="true" />
              Restore
            </button>
          )}
          {stateOf(template) !== 'retired' && template.can_delete && (
            <button
              type="button"
              onClick={() => void onRetire(template)}
              disabled={saving === template.id}
              className="inline-flex items-center gap-1 text-xs font-medium text-destructive disabled:opacity-60"
            >
              <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
              Retire
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <div>
      <PageHeader
        title="Templates"
        description="The wording we send over and over — rendered with the record's own details, never invented."
        actions={
          isManagerOrAbove ? (
            <Link
              to="/communications/templates/new"
              className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
            >
              <FilePlus2 className="h-4 w-4" aria-hidden="true" />
              New template
            </Link>
          ) : undefined
        }
      />

      {notice && (
        <div className="mb-4">
          <AlertItem variant="success">{notice}</AlertItem>
        </div>
      )}

      {error && (
        <div className="mb-4">
          <AlertItem variant="danger">{error}</AlertItem>
        </div>
      )}

      <form
        className="mb-4 flex flex-wrap items-center gap-2"
        onSubmit={(event) => {
          event.preventDefault()
          void load(1)
        }}
      >
        <div className="relative w-full max-w-sm">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search the name or the wording"
            className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          aria-label="Channel filter"
          value={channel}
          onChange={(event) => setChannel(event.target.value)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All channels</option>
          {(Object.keys(communicationChannelMeta) as CommunicationChannel[]).map((value) => (
            <option key={value} value={value}>
              {communicationChannelMeta[value].label}
            </option>
          ))}
        </select>

        <select
          aria-label="State filter"
          value={state}
          onChange={(event) => setState(event.target.value as TemplateState)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All states</option>
          <option value="active">Active — in the picker</option>
          <option value="inactive">Inactive — kept, hidden</option>
          <option value="retired">Retired</option>
        </select>

        <button
          type="submit"
          className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
          Filter
        </button>
      </form>

      <DataTable
        columns={columns}
        rows={templates}
        rowKey={(template) => template.id}
        loading={loading}
        loadingLabel="Loading templates…"
        pagination={{
          currentPage: page,
          lastPage,
          total,
          onPageChange: (next) => void load(next),
        }}
        empty={
          <EmptyState
            title="No templates yet"
            description={
              isManagerOrAbove
                ? 'Write the wording once and start any message from it.'
                : 'A manager has not written any wording yet.'
            }
            action={
              isManagerOrAbove ? (
                <Link
                  to="/communications/templates/new"
                  className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
                >
                  <FilePlus2 className="h-4 w-4" aria-hidden="true" />
                  New template
                </Link>
              ) : undefined
            }
          />
        }
      />
    </div>
  )
}
