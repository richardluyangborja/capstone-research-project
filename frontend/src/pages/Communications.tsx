import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { MailPlus, MessagesSquare, Paperclip, RefreshCw, Search } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import StatCard from '../components/common/StatCard'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import {
  listCommunications,
  syncInboundCommunications,
  type CommunicationFilters,
} from '../lib/communications'
import { useAuth } from '../lib/auth-context'
import {
  communicationChannelMeta,
  communicationDirectionMeta,
  communicationRecordPath,
  communicationStatusMeta,
  communicationSubjectLabels,
} from '../lib/communicationMeta'
import { formatDateTime, formatDate } from '../lib/format'
import type {
  Communication,
  CommunicationChannel,
  CommunicationDirection,
  CommunicationStatus,
  CommunicationSubjectType,
  CommunicationSummary,
} from '../types'

const columns: DataTableColumn<Communication>[] = [
  {
    key: 'occurred_at',
    header: 'When',
    className: 'whitespace-nowrap text-muted-foreground',
    render: (communication) => formatDateTime(communication.occurred_at),
  },
  {
    key: 'channel',
    header: 'Channel',
    render: (communication) => {
      const channel = communicationChannelMeta[communication.channel]
      const direction = communicationDirectionMeta[communication.direction]
      return (
        <div className="flex flex-wrap items-center gap-1.5">
          <StatusBadge tone={channel.tone}>{channel.label}</StatusBadge>
          <StatusBadge tone={direction.tone}>{direction.label}</StatusBadge>
        </div>
      )
    },
  },
  {
    key: 'subject',
    header: 'Subject',
    render: (communication) => (
      <>
        <Link to={`/communications/${communication.id}`} className="font-medium text-foreground">
          {communication.subject}
        </Link>
        <div className="font-mono text-xs text-muted-foreground">{communication.reference}</div>
        {communication.attachments_count ? (
          <div className="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground">
            <Paperclip className="h-3 w-3" aria-hidden />
            {communication.attachments_count}{' '}
            {communication.attachments_count === 1 ? 'file' : 'files'}
          </div>
        ) : null}
        {communication.thread_id ? (
          <div className="mt-1">
            <Link
              to={`/communications/threads/${communication.thread_id}`}
              className="inline-flex items-center gap-1 text-xs font-medium text-counts"
            >
              <MessagesSquare className="h-3 w-3" aria-hidden />
              In a conversation
            </Link>
          </div>
        ) : null}
      </>
    ),
  },
  {
    key: 'record',
    header: 'Record',
    render: (communication) => {
      const href = communicationRecordPath(communication.record)
      const label = communication.record?.label ?? '—'
      return href ? (
        <Link to={href} className="text-foreground hover:underline">
          {label}
        </Link>
      ) : (
        label
      )
    },
  },
  {
    key: 'contact',
    header: 'Contact',
    className: 'text-muted-foreground',
    render: (communication) => communication.contact?.name ?? '—',
  },
  {
    key: 'status',
    header: 'Status',
    render: (communication) => (
      <StatusBadge tone={communicationStatusMeta[communication.status].tone}>
        {communicationStatusMeta[communication.status].label}
      </StatusBadge>
    ),
  },
  {
    key: 'owner',
    header: 'Logged by',
    className: 'text-muted-foreground',
    render: (communication) => communication.owner?.name ?? '—',
  },
]

// Module 03 §UI — the communication book: every message, call and meeting the caller may see,
// newest first, across every record type. Logging happens here or from a record's own page.
export default function Communications() {
  const [searchParams] = useSearchParams()
  const { user } = useAuth()
  const [communications, setCommunications] = useState<Communication[]>([])
  const [summary, setSummary] = useState<CommunicationSummary | null>(null)
  const [search, setSearch] = useState('')
  const [channel, setChannel] = useState('')
  const [direction, setDirection] = useState('')
  const [status, setStatus] = useState('')
  const [subjectType, setSubjectType] = useState<CommunicationSubjectType | ''>(
    (searchParams.get('subject_type') as CommunicationSubjectType | null) ?? '',
  )
  const [subjectId] = useState(searchParams.get('subject_id') ?? '')
  const [company] = useState(searchParams.get('company') ?? '')
  const [owner, setOwner] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [syncing, setSyncing] = useState(false)
  const [syncNote, setSyncNote] = useState('')

  // Pulling the mailbox rewrites history across every team, so it is an admin action — the
  // salesrep's own demo runs `php artisan communication:sync-inbound` meanwhile (spec 11 §S2).
  const canSyncMailbox = user?.role === 'admin' || user?.role === 'superadmin'

  const buildFilters = useCallback(
    (targetPage: number): CommunicationFilters => ({
      search: search || undefined,
      channel: channel || undefined,
      direction: direction || undefined,
      status: status || undefined,
      subject_type: subjectType || undefined,
      subject_id: subjectType ? subjectId || undefined : undefined,
      company: company || undefined,
      owner: owner || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      page: targetPage,
    }),
    [search, channel, direction, status, subjectType, subjectId, company, owner, dateFrom, dateTo],
  )

  const load = useCallback(
    async (targetPage: number) => {
      setLoading(true)
      setError('')
      try {
        const response = await listCommunications(buildFilters(targetPage))
        setCommunications(response.data)
        setSummary(response.summary)
        setPage(response.meta.current_page)
        setLastPage(response.meta.last_page)
        setTotal(response.meta.total)
      } catch {
        setError('Could not load communications. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [buildFilters],
  )

  useEffect(() => {
    void load(1)
  }, [load])

  const onSyncMailbox = async () => {
    setSyncing(true)
    setError('')
    setSyncNote('')
    try {
      const result = await syncInboundCommunications()
      setSyncNote(
        result.recorded === 0
          ? 'The simulated mailbox holds nothing new.'
          : `${result.recorded} ${
              result.recorded === 1 ? 'reply' : 'replies'
            } pulled in from the simulated mailbox.`,
      )
      await load(1)
    } catch {
      setError('Could not reach the simulated mailbox.')
    } finally {
      setSyncing(false)
    }
  }

  return (
    <div>
      <PageHeader
        title="Communication history"
        description="Every call, email, meeting and letter logged against a lead, account, contact, deal or contract."
        actions={
          <>
            {canSyncMailbox && (
              <button
                type="button"
                onClick={() => void onSyncMailbox()}
                disabled={syncing}
                className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
              >
                <RefreshCw className="h-4 w-4" aria-hidden="true" />
                {syncing ? 'Pulling…' : 'Pull replies'}
              </button>
            )}
            <Link
              to="/communications/threads"
              className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
            >
              <MessagesSquare className="h-4 w-4" aria-hidden="true" />
              Conversations
            </Link>
            <Link
              to={
                subjectType && subjectId
                  ? `/communications/new?subject_type=${subjectType}&subject_id=${subjectId}`
                  : '/communications/new'
              }
              className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
            >
              <MailPlus className="h-4 w-4" aria-hidden="true" />
              Log communication
            </Link>
          </>
        }
      />

      {syncNote && (
        <div className="mb-4">
          <AlertItem variant="success">{syncNote}</AlertItem>
        </div>
      )}

      {summary && (
        <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatCard label="All entries" value={summary.all} />
          <StatCard label="Inbound" value={summary.by_direction.inbound ?? 0} domain="people" />
          <StatCard label="Outbound" value={summary.by_direction.outbound ?? 0} domain="activity" />
          <StatCard
            label="Last contacted"
            value={summary.last_contacted_at ? formatDate(summary.last_contacted_at) : '—'}
            domain="cost"
          />
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
            placeholder="Search subject, body or reference"
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
          aria-label="Direction filter"
          value={direction}
          onChange={(event) => setDirection(event.target.value)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">Both directions</option>
          {(Object.keys(communicationDirectionMeta) as CommunicationDirection[]).map((value) => (
            <option key={value} value={value}>
              {communicationDirectionMeta[value].label}
            </option>
          ))}
        </select>

        <select
          aria-label="Status filter"
          value={status}
          onChange={(event) => setStatus(event.target.value)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">Any status</option>
          {(Object.keys(communicationStatusMeta) as CommunicationStatus[]).map((value) => (
            <option key={value} value={value}>
              {communicationStatusMeta[value].label}
            </option>
          ))}
        </select>

        <select
          aria-label="Record type filter"
          value={subjectType}
          onChange={(event) => setSubjectType(event.target.value as CommunicationSubjectType | '')}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All records</option>
          {(Object.keys(communicationSubjectLabels) as CommunicationSubjectType[]).map((value) => (
            <option key={value} value={value}>
              {communicationSubjectLabels[value]}
            </option>
          ))}
        </select>

        <select
          aria-label="Owner filter"
          value={owner}
          onChange={(event) => setOwner(event.target.value)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All owners</option>
          <option value="me">Logged by me</option>
          <option value="team">My team</option>
        </select>

        <label className="inline-flex items-center gap-2 text-sm text-foreground">
          From
          <input
            type="date"
            aria-label="From date"
            value={dateFrom}
            onChange={(event) => setDateFrom(event.target.value)}
            className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </label>

        <label className="inline-flex items-center gap-2 text-sm text-foreground">
          To
          <input
            type="date"
            aria-label="To date"
            value={dateTo}
            onChange={(event) => setDateTo(event.target.value)}
            className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </label>

        <button
          type="submit"
          className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
          Filter
        </button>
      </form>

      {error && (
        <div className="mb-4">
          <AlertItem variant="danger">{error}</AlertItem>
        </div>
      )}

      <DataTable
        columns={columns}
        rows={communications}
        rowKey={(communication) => communication.id}
        loading={loading}
        loadingLabel="Loading communications…"
        pagination={{
          currentPage: page,
          lastPage,
          total,
          onPageChange: (next) => void load(next),
        }}
        empty={
          <EmptyState
            title="No communications yet"
            description="Log the first call, email or meeting against a lead, account or deal."
            action={
              <Link
                to="/communications/new"
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
              >
                <MailPlus className="h-4 w-4" aria-hidden="true" />
                Log communication
              </Link>
            }
          />
        }
      />
    </div>
  )
}
