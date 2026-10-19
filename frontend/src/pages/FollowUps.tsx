import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { CalendarPlus, MailCheck, Search } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import StatCard from '../components/common/StatCard'
import RescheduleDialog from '../components/common/RescheduleDialog'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import { useAuth } from '../lib/auth-context'
import { listFollowUps, updateTask, updateTaskStatus, type FollowUpFilters } from '../lib/followUps'
import {
  deleteCommunication,
  dispatchDueCommunications,
  sendCommunication,
} from '../lib/communications'
import { communicationRecordPath, communicationSubjectLabels } from '../lib/communicationMeta'
import {
  followUpDueMeta,
  followUpKindMeta,
  followUpKindOptions,
  followUpRowStatusMeta,
  followUpStateOptions,
} from '../lib/followUpMeta'
import { formatDateTime } from '../lib/format'
import { parseValidationError } from '../lib/leads'
import type { CommunicationSubjectType, FollowUp, FollowUpCounts } from '../types'

type OwnerTab = 'all' | 'me' | 'team'

const ownerTabs: { key: OwnerTab; label: string }[] = [
  { key: 'all', label: 'Everyone' },
  { key: 'me', label: 'Mine' },
  { key: 'team', label: 'My team' },
]

const emptyCounts: FollowUpCounts = { overdue: 0, today: 0, upcoming: 0, open: 0, done: 0 }

// Module 04 §UI — the worklist: everything owed, by when, in one feed. A read model over the
// records that hold a promise (spec 12 §S1), so a row is actioned here but lives on its record.
export default function FollowUps() {
  const [searchParams] = useSearchParams()
  const { user } = useAuth()
  const [search, setSearch] = useState('')
  const [owner, setOwner] = useState<OwnerTab>('all')
  const [state, setState] = useState('open')
  const [kind, setKind] = useState('')
  const [recordType, setRecordType] = useState<CommunicationSubjectType | ''>(
    (searchParams.get('subject_type') as CommunicationSubjectType | null) ?? '',
  )
  const [recordId, setRecordId] = useState(searchParams.get('subject_id') ?? '')
  const [followUps, setFollowUps] = useState<FollowUp[]>([])
  const [counts, setCounts] = useState<FollowUpCounts>(emptyCounts)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [busyId, setBusyId] = useState('')
  const [rescheduling, setRescheduling] = useState<FollowUp | null>(null)
  const [dispatching, setDispatching] = useState(false)
  const [dispatchNote, setDispatchNote] = useState('')

  // Sending every due message writes across every team, so it is an admin action — the
  // salesrep's own demo runs `php artisan communication:send-due` meanwhile (spec 12 §S2).
  const canDispatch = user?.role === 'admin' || user?.role === 'superadmin'

  const load = useCallback(
    async (targetPage: number) => {
      setLoading(true)
      setError('')
      try {
        const filters: FollowUpFilters = {
          search: search || undefined,
          owner: owner === 'all' ? undefined : owner,
          state: state || undefined,
          kind: kind || undefined,
          record_type: recordType || undefined,
          record_id: recordType ? recordId || undefined : undefined,
          page: targetPage,
        }
        const response = await listFollowUps(filters)
        setFollowUps(response.data)
        setCounts(response.counts)
        setPage(response.meta.current_page)
        setLastPage(response.meta.last_page)
        setTotal(response.meta.total)
      } catch {
        setError('Could not load follow-ups. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [search, owner, state, kind, recordType, recordId],
  )

  useEffect(() => {
    void load(1)
  }, [load])

  /** Every row action is the same shape: write, then re-read the list and its counts. */
  const act = async (row: FollowUp, run: () => Promise<unknown>, fallback: string) => {
    setBusyId(row.id)
    setError('')
    try {
      await run()
      await load(page)
    } catch (caught) {
      const validation = parseValidationError(caught)
      setError(validation?.message ?? fallback)
    } finally {
      setBusyId('')
    }
  }

  /**
   * An admin sends everything that has fallen due (spec 12 §S2, §6). Nothing runs on a timer in
   * this phase, so the demo clicks this — and the note says so, because the message really does
   * leave the mocked gateway immediately rather than at its scheduled minute.
   */
  const onDispatchDue = async () => {
    setDispatching(true)
    setError('')
    setDispatchNote('')
    try {
      const result = await dispatchDueCommunications()
      setDispatchNote(
        result.attempted === 0
          ? 'Nothing was due — no scheduled message has come due since the last run.'
          : `${result.sent} of ${result.attempted} due ${
              result.attempted === 1 ? 'message' : 'messages'
            } sent through the simulated mailbox${
              result.failed > 0 ? `, ${result.failed} failed` : ''
            }.`,
      )
      await load(page)
    } catch {
      setError('Could not dispatch the due messages.')
    } finally {
      setDispatching(false)
    }
  }

  const columns: DataTableColumn<FollowUp>[] = [
    {
      key: 'due',
      header: 'Due',
      className: 'whitespace-nowrap',
      render: (row) => {
        const due = followUpDueMeta[row.due.state]
        return (
          <div className="flex flex-col items-start gap-1">
            <StatusBadge tone={due.tone}>{due.label}</StatusBadge>
            <span className="text-xs text-muted-foreground">{formatDateTime(row.due_at)}</span>
          </div>
        )
      },
    },
    {
      key: 'subject',
      header: 'Follow-up',
      render: (row) => (
        <>
          {/* A task lives in this worklist; a message lives in the history book. */}
          <Link
            to={row.kind === 'message' ? `/communications/${row.id}` : `/follow-ups/${row.id}`}
            className="font-medium text-foreground"
          >
            {row.subject}
          </Link>
          {row.body && (
            <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{row.body}</p>
          )}
          {row.kind === 'message' && row.recipient && (
            <p className="mt-0.5 text-xs text-muted-foreground">To {row.recipient}</p>
          )}
        </>
      ),
    },
    {
      key: 'kind',
      header: 'Kind',
      render: (row) => {
        const kind = followUpKindMeta[row.kind]
        return <StatusBadge tone={kind.tone}>{kind.label}</StatusBadge>
      },
    },
    {
      key: 'record',
      header: 'Record',
      render: (row) => {
        const href = communicationRecordPath(row.record)
        const label = row.record?.label ?? '—'
        return href ? (
          <Link to={href} className="text-foreground hover:underline">
            {label}
          </Link>
        ) : (
          <span className="text-foreground">{label}</span>
        )
      },
    },
    {
      key: 'owner',
      header: 'Owes it',
      className: 'text-muted-foreground',
      render: (row) => row.owner?.name ?? '—',
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => {
        const status = followUpRowStatusMeta[row.status.value]
        return <StatusBadge tone={status.tone}>{status.label}</StatusBadge>
      },
    },
    {
      key: 'actions',
      header: 'Actions',
      render: (row) => {
        const busy = busyId === row.id

        // A scheduled message is not a task: it is sent or retired, never completed or moved
        // (spec 12 §S2). Its Cancel is the soft delete the history book already has.
        if (row.kind === 'message') {
          return (
            <div className="flex flex-wrap items-center gap-2 text-xs font-medium">
              {row.can_send && (
                <button
                  type="button"
                  disabled={busy}
                  onClick={() =>
                    void act(row, () => sendCommunication(row.id), 'Could not send it.')
                  }
                  className="rounded-md border border-border px-2 py-1 text-foreground hover:bg-muted disabled:opacity-60"
                >
                  Send now
                </button>
              )}
              <Link
                to={`/communications/${row.id}`}
                className="rounded-md border border-border px-2 py-1 text-foreground hover:bg-muted"
              >
                Open
              </Link>
              {row.can_delete && (
                <button
                  type="button"
                  disabled={busy}
                  onClick={() =>
                    void act(row, () => deleteCommunication(row.id), 'Could not cancel it.')
                  }
                  className="rounded-md border border-border px-2 py-1 text-muted-foreground hover:bg-muted disabled:opacity-60"
                >
                  Cancel
                </button>
              )}
            </div>
          )
        }

        return (
          <div className="flex flex-wrap items-center gap-2 text-xs font-medium">
            {row.can_transition && row.status.value === 'open' && (
              <button
                type="button"
                disabled={busy}
                onClick={() =>
                  void act(row, () => updateTaskStatus(row.id, 'done'), 'Could not complete it.')
                }
                className="rounded-md border border-border px-2 py-1 text-foreground hover:bg-muted disabled:opacity-60"
              >
                Complete
              </button>
            )}
            {row.can_update && row.status.value === 'open' && (
              <button
                type="button"
                disabled={busy}
                onClick={() => setRescheduling(row)}
                className="rounded-md border border-border px-2 py-1 text-foreground hover:bg-muted disabled:opacity-60"
              >
                Reschedule
              </button>
            )}
            {row.can_transition && row.status.value === 'open' && (
              <button
                type="button"
                disabled={busy}
                onClick={() =>
                  void act(row, () => updateTaskStatus(row.id, 'cancelled'), 'Could not cancel it.')
                }
                className="rounded-md border border-border px-2 py-1 text-muted-foreground hover:bg-muted disabled:opacity-60"
              >
                Cancel
              </button>
            )}
            {row.can_transition && row.status.value !== 'open' && (
              <button
                type="button"
                disabled={busy}
                onClick={() =>
                  void act(row, () => updateTaskStatus(row.id, 'open'), 'Could not reopen it.')
                }
                className="rounded-md border border-border px-2 py-1 text-foreground hover:bg-muted disabled:opacity-60"
              >
                Reopen
              </button>
            )}
          </div>
        )
      },
    },
  ]

  const narrowedToRecord = recordType !== '' && recordId !== ''

  return (
    <div>
      <PageHeader
        title="Follow-up reminders"
        description="What is owed, by when and by whom — across leads, accounts, contacts and deals."
        actions={
          <>
            {canDispatch && (
              <button
                type="button"
                onClick={() => void onDispatchDue()}
                disabled={dispatching}
                className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
              >
                <MailCheck className="h-4 w-4" aria-hidden="true" />
                {dispatching ? 'Dispatching…' : 'Dispatch due sends'}
              </button>
            )}
            <Link
              to={
                narrowedToRecord
                  ? `/follow-ups/new?subject_type=${recordType}&subject_id=${recordId}`
                  : '/follow-ups/new'
              }
              className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
            >
              <CalendarPlus className="h-4 w-4" aria-hidden="true" />
              New follow-up
            </Link>
          </>
        }
      />

      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Overdue" value={counts.overdue} domain="cost" />
        <StatCard label="Due today" value={counts.today} />
        <StatCard label="Upcoming" value={counts.upcoming} />
        <StatCard label="Completed" value={counts.done} domain="people" />
      </div>

      {dispatchNote && (
        <div className="mb-4">
          <AlertItem variant="success">{dispatchNote}</AlertItem>
        </div>
      )}

      {canDispatch && (
        <p className="mb-4 text-xs text-muted-foreground">
          Nothing runs on a timer in this phase: <strong>Dispatch due sends</strong> takes the
          scheduled emails whose moment has passed through the simulated mailbox, right now. A
          second run finds nothing, because what went out is no longer scheduled.
        </p>
      )}

      {narrowedToRecord && (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm">
          <span className="text-muted-foreground">
            Showing follow-ups for{' '}
            <span className="font-medium text-foreground">
              {communicationSubjectLabels[recordType as CommunicationSubjectType]}
            </span>{' '}
            only.
          </span>
          <button
            type="button"
            onClick={() => {
              setRecordType('')
              setRecordId('')
            }}
            className="text-xs font-medium text-counts"
          >
            Show every record
          </button>
        </div>
      )}

      <div className="mb-4 flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1">
        {ownerTabs.map((tab) => {
          const active = tab.key === owner
          return (
            <button
              key={tab.key}
              type="button"
              onClick={() => setOwner(tab.key)}
              className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                active ? 'bg-secondary text-primary' : 'text-muted-foreground hover:bg-muted'
              }`}
            >
              {tab.label}
            </button>
          )
        })}
      </div>

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
            placeholder="Search the follow-up or its notes"
            className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          aria-label="State filter"
          value={state}
          onChange={(event) => setState(event.target.value)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          {followUpStateOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        <select
          aria-label="Kind filter"
          value={kind}
          onChange={(event) => setKind(event.target.value)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          {followUpKindOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        <select
          aria-label="Record type filter"
          value={recordType}
          onChange={(event) => {
            setRecordType(event.target.value as CommunicationSubjectType | '')
            setRecordId('')
          }}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All records</option>
          {(Object.keys(communicationSubjectLabels) as CommunicationSubjectType[]).map((value) => (
            <option key={value} value={value}>
              {communicationSubjectLabels[value]}
            </option>
          ))}
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
        rows={followUps}
        rowKey={(row) => row.id}
        loading={loading}
        loadingLabel="Loading follow-ups…"
        pagination={{ currentPage: page, lastPage, total, onPageChange: (next) => void load(next) }}
        empty={
          <EmptyState
            title="Nothing is owed"
            description="Put something owed on a lead, account, contact or deal and it lands here."
            action={
              <Link
                to="/follow-ups/new"
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
              >
                <CalendarPlus className="h-4 w-4" aria-hidden="true" />
                New follow-up
              </Link>
            }
          />
        }
      />

      {rescheduling && (
        <RescheduleDialog
          subject={rescheduling.subject}
          dueAt={rescheduling.due_at}
          busy={busyId === rescheduling.id}
          onCancel={() => setRescheduling(null)}
          onConfirm={(dueAt) => {
            const row = rescheduling
            setRescheduling(null)
            void act(row, () => updateTask(row.id, { due_at: dueAt }), 'Could not reschedule it.')
          }}
        />
      )}
    </div>
  )
}
