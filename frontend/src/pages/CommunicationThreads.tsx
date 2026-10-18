import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ArrowDownLeft, ArrowUpRight, MailPlus, MessagesSquare, Search } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import { listCommunicationThreads, type CommunicationThreadFilters } from '../lib/communications'
import {
  communicationChannelMeta,
  communicationDirectionMeta,
  communicationRecordPath,
  communicationSubjectLabels,
} from '../lib/communicationMeta'
import { formatDateTime } from '../lib/format'
import type { CommunicationChannel, CommunicationSubjectType, CommunicationThread } from '../types'

const columns: DataTableColumn<CommunicationThread>[] = [
  {
    key: 'title',
    header: 'Conversation',
    render: (thread) => (
      <>
        <Link
          to={`/communications/threads/${thread.thread_id}`}
          className="font-medium text-foreground"
        >
          {thread.title ?? 'Untitled conversation'}
        </Link>
        <div className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
          <MessagesSquare className="h-3 w-3" aria-hidden />
          {thread.message_count} {thread.message_count === 1 ? 'message' : 'messages'}
        </div>
      </>
    ),
  },
  {
    key: 'record',
    header: 'Record',
    render: (thread) => {
      const href = communicationRecordPath(thread.record)
      const label = thread.record?.label ?? '—'
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
    key: 'channel',
    header: 'Channel',
    render: (thread) =>
      thread.channel ? (
        <StatusBadge tone={communicationChannelMeta[thread.channel].tone}>
          {communicationChannelMeta[thread.channel].label}
        </StatusBadge>
      ) : (
        '—'
      ),
  },
  {
    key: 'last_message',
    header: 'Last word',
    render: (thread) => {
      const last = thread.last_message
      if (!last) return '—'
      const inbound = last.direction === 'inbound'
      const DirectionIcon = inbound ? ArrowDownLeft : ArrowUpRight
      const direction = communicationDirectionMeta[last.direction]
      return (
        <div className="flex flex-wrap items-center gap-1.5">
          <StatusBadge tone={direction.tone}>
            <span className="inline-flex items-center gap-1">
              <DirectionIcon className="h-3 w-3" aria-hidden />
              {direction.label}
            </span>
          </StatusBadge>
          <span className="text-muted-foreground">
            {last.from_name ?? '—'} · <span className="font-mono text-xs">{last.reference}</span>
          </span>
        </div>
      )
    },
  },
  {
    key: 'last_at',
    header: 'Last activity',
    className: 'whitespace-nowrap text-muted-foreground',
    render: (thread) => formatDateTime(thread.last_at),
  },
]

// Module 03 §UI (slice S4) — the conversations: messages grouped by the exchange they belong
// to, so a thread of replies reads as one story instead of a run of separate rows. A message
// nobody answered is not a conversation and only appears in the history book.
export default function CommunicationThreads() {
  const [searchParams] = useSearchParams()
  const [threads, setThreads] = useState<CommunicationThread[]>([])
  const [search, setSearch] = useState('')
  const [channel, setChannel] = useState('')
  const [subjectType, setSubjectType] = useState<CommunicationSubjectType | ''>(
    (searchParams.get('subject_type') as CommunicationSubjectType | null) ?? '',
  )
  const [subjectId] = useState(searchParams.get('subject_id') ?? '')
  const [owner, setOwner] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(
    async (targetPage: number) => {
      setLoading(true)
      setError('')
      try {
        const filters: CommunicationThreadFilters = {
          search: search || undefined,
          channel: channel || undefined,
          subject_type: subjectType || undefined,
          subject_id: subjectType ? subjectId || undefined : undefined,
          owner: owner || undefined,
          page: targetPage,
        }
        const response = await listCommunicationThreads(filters)
        setThreads(response.data)
        setPage(response.meta.current_page)
        setLastPage(response.meta.last_page)
        setTotal(response.meta.total)
      } catch {
        setError('Could not load the conversations. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [search, channel, subjectType, subjectId, owner],
  )

  useEffect(() => {
    void load(1)
  }, [load])

  return (
    <div>
      <PageHeader
        title="Conversations"
        description="Exchanges that went back and forth — every message that answers another, grouped."
        actions={
          <>
            <Link
              to={
                subjectType && subjectId
                  ? `/communications?subject_type=${subjectType}&subject_id=${subjectId}`
                  : '/communications'
              }
              className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
            >
              <MessagesSquare className="h-4 w-4" aria-hidden="true" />
              History book
            </Link>
            <Link
              to="/communications/new"
              className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
            >
              <MailPlus className="h-4 w-4" aria-hidden="true" />
              Log communication
            </Link>
          </>
        }
      />

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
            placeholder="Search a conversation's messages"
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
          <option value="me">My messages</option>
          <option value="team">My team</option>
        </select>

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
        rows={threads}
        rowKey={(thread) => thread.thread_id}
        loading={loading}
        loadingLabel="Loading conversations…"
        pagination={{
          currentPage: page,
          lastPage,
          total,
          onPageChange: (next) => void load(next),
        }}
        empty={
          <EmptyState
            title="No conversations yet"
            description="A conversation appears once a message is answered. Open a message and reply to start one."
            action={
              <Link
                to="/communications"
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
              >
                <MessagesSquare className="h-4 w-4" aria-hidden="true" />
                Open the history book
              </Link>
            }
          />
        }
      />
    </div>
  )
}
