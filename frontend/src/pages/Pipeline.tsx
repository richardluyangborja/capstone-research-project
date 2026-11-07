import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Columns3, Filter, List, Plus, Search, Table2 } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatCard from '../components/common/StatCard'
import StatusBadge from '../components/common/StatusBadge'
import StaffingBadge from '../components/common/StaffingBadge'
import DealFollowUpBadge from '../components/common/DealFollowUpBadge'
import AlertItem from '../components/common/AlertItem'
import EmptyState from '../components/common/EmptyState'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import StageMoveControl from '../components/common/StageMoveControl'
import LostReasonDialog from '../components/common/LostReasonDialog'
import WonConfirmDialog, { type WonOutcome } from '../components/common/WonConfirmDialog'
import PipelineFunnel from '../components/pipeline/PipelineFunnel'
import DealPeekDrawer from '../components/pipeline/DealPeekDrawer'
import { contractStatusMeta } from '../lib/contractMeta'
import { getPipeline, moveOpportunityStage, type OpportunityFilters } from '../lib/opportunities'
import { opportunityStageMeta, openStageOrder, followUpWarningStages } from '../lib/opportunityMeta'
import { formatCurrency, formatDate } from '../lib/format'
import type { LostReason, Opportunity, OpportunityStage, PipelineBoard } from '../types'

type PipelineView = 'board' | 'table' | 'funnel'

const pipelineViews: { id: PipelineView; label: string; icon: typeof Columns3 }[] = [
  { id: 'board', label: 'Board', icon: Columns3 },
  { id: 'table', label: 'Table', icon: Table2 },
  { id: 'funnel', label: 'Funnel', icon: Filter },
]

const dealTableColumns: DataTableColumn<Opportunity>[] = [
  {
    key: 'deal',
    header: 'Deal',
    render: (deal) => (
      <div className="flex flex-col">
        <Link
          to={`/opportunities/${deal.id}`}
          className="font-medium text-foreground hover:underline"
        >
          {deal.name}
        </Link>
        <span className="font-mono text-xs text-muted-foreground">{deal.reference}</span>
      </div>
    ),
  },
  {
    key: 'account',
    header: 'Account',
    render: (deal) => (
      <Link
        to={`/accounts/${deal.company_id}`}
        className="text-foreground hover:underline"
      >
        {deal.company?.name ?? '—'}
      </Link>
    ),
  },
  {
    key: 'stage',
    header: 'Stage',
    render: (deal) => (
      <StatusBadge tone={opportunityStageMeta[deal.stage].tone}>
        {opportunityStageMeta[deal.stage].label}
      </StatusBadge>
    ),
  },
  {
    key: 'staffing',
    header: 'Staffing',
    render: (deal) => (
      <StaffingBadge
        estimatedHeadcount={deal.estimated_headcount}
        serviceType={deal.service_type}
      />
    ),
  },
  {
    key: 'follow_up',
    header: 'Follow-up',
    render: (deal) => <DealFollowUpBadge deal={deal} />,
  },
  {
    key: 'value',
    header: 'Value',
    className: 'font-medium text-cost',
    headerClassName: 'text-right',
    render: (deal) => <div className="text-right">{formatCurrency(deal.amount)}</div>,
  },
  {
    key: 'owner',
    header: 'Owner',
    className: 'text-muted-foreground',
    render: (deal) => deal.owner?.name ?? 'Unassigned',
  },
  {
    key: 'close',
    header: 'Expected close',
    className: 'text-muted-foreground',
    render: (deal) => formatDate(deal.expected_close_date) || '—',
  },
]

type OwnerFilter = '' | 'me' | 'team'

interface MoveOptions {
  reason?: LostReason
  note?: string
  outcome?: WonOutcome
}

// Module 02 §1 — the pipeline board. Columns are the open stages; deals drag between them,
// every card also carries a Move-to menu (keyboard) and won/lost live in the Closed column.
export default function Pipeline() {
  const [board, setBoard] = useState<PipelineBoard | null>(null)
  const [owner, setOwner] = useState<OwnerFilter>('')
  const [closeWindow, setCloseWindow] = useState('')
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [busyId, setBusyId] = useState<string | null>(null)
  const [pendingLost, setPendingLost] = useState<Opportunity | null>(null)
  const [pendingWon, setPendingWon] = useState<Opportunity | null>(null)
  const [wonNotice, setWonNotice] = useState<Opportunity | null>(null)
  const [view, setView] = useState<PipelineView>('board')
  const [peek, setPeek] = useState<Opportunity | null>(null)

  const filters: OpportunityFilters = {
    owner: owner || undefined,
    close_window: closeWindow || undefined,
    search: search || undefined,
  }

  const load = useCallback(async (next: OpportunityFilters) => {
    setLoading(true)
    setError('')
    try {
      setBoard(await getPipeline(next))
    } catch {
      setError('Could not load the pipeline. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load({ owner: undefined, close_window: undefined, search: undefined })
  }, [load])

  const submitMove = async (
    deal: Opportunity,
    stage: OpportunityStage,
    { reason, note, outcome }: MoveOptions = {},
  ) => {
    setBusyId(deal.id)
    setError('')
    try {
      const updated = await moveOpportunityStage(deal.id, {
        stage,
        lost_reason: reason ?? null,
        note: note || undefined,
        actual_close_date: outcome?.actual_close_date,
        win_note: outcome?.win_note,
        amount: outcome?.amount,
      })
      // ADR-0027 — a won deal reports its read-only contract projection (if any) and
      // never prompts contract creation (spec 18 S3).
      setWonNotice(stage === 'won' ? updated : null)
      await load(filters)
    } catch {
      setError('Could not move the deal. Please try again.')
    } finally {
      setBusyId(null)
      setPendingLost(null)
      setPendingWon(null)
    }
  }

  const requestMove = (deal: Opportunity, stage: OpportunityStage) => {
    if (stage === 'lost') {
      setPendingLost(deal)
      return
    }
    if (stage === 'won') {
      setPendingWon(deal)
      return
    }
    void submitMove(deal, stage)
  }

  const columns = board?.data.columns ?? []
  const closed = board?.data.closed ?? []
  const won = closed.filter((deal) => deal.is_won)
  const lost = closed.filter((deal) => deal.is_lost)
  const tableDeals = [...columns.flatMap((column) => column.deals), ...closed]
  const meta = board?.meta

  const byId = new Map<string, Opportunity>()
  for (const deal of [...columns.flatMap((column) => column.deals), ...closed]) {
    byId.set(deal.id, deal)
  }

  const dropMove = (dealId: string, stage: OpportunityStage) => {
    const deal = byId.get(dealId)
    if (deal) requestMove(deal, stage)
  }

  return (
    <div>
      <PageHeader
        title="Opportunity pipeline"
        description="Every open deal, by stage. Drag a card, or use its Move-to menu."
        actions={
          <>
            <div
              role="tablist"
              aria-label="Pipeline view"
              className="inline-flex rounded-md border border-border bg-card p-0.5"
            >
              {pipelineViews.map(({ id, label, icon: Icon }) => (
                <button
                  key={id}
                  type="button"
                  role="tab"
                  aria-selected={view === id}
                  onClick={() => setView(id)}
                  className={`inline-flex items-center gap-1.5 rounded px-2.5 py-1.5 text-sm font-medium ${
                    view === id
                      ? 'bg-primary text-primary-foreground'
                      : 'text-muted-foreground hover:text-foreground'
                  }`}
                >
                  <Icon className="h-3.5 w-3.5" aria-hidden="true" />
                  {label}
                </button>
              ))}
            </div>
            <Link
              to="/opportunities"
              className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
            >
              <List className="h-4 w-4" aria-hidden="true" />
              Deal list
            </Link>
            <Link
              to="/opportunities/new"
              className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
            >
              <Plus className="h-4 w-4" aria-hidden="true" />
              New deal
            </Link>
          </>
        }
      />

      {meta && (
        <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatCard label="Open pipeline" value={formatCurrency(meta.open_value)} domain="cost" />
          <StatCard
            label="Weighted forecast"
            value={formatCurrency(meta.weighted_value)}
            domain="cost"
          />
          <StatCard
            label="Win rate"
            value={meta.win_rate === null ? '—' : `${meta.win_rate}%`}
            domain="people"
          />
          <StatCard label="Stalled deals" value={meta.stale_count} domain="activity" />
        </div>
      )}

      {wonNotice && (
        <div className="mb-4">
          <AlertItem variant="success">
            {wonNotice.contracts && wonNotice.contracts.length > 0 ? (
              <p>
                “{wonNotice.name}” is won. Linked contract:{' '}
                {wonNotice.contracts.map((contract) => (
                  <Link
                    key={contract.id}
                    to={`/contracts/${contract.id}`}
                    className="font-medium text-foreground underline"
                  >
                    {contract.reference} ({contractStatusMeta[contract.status].label})
                  </Link>
                ))}
                .
              </p>
            ) : (
              <p>
                “{wonNotice.name}” is won. Contracts are managed in Contract Management (Core
                Transaction 3).
              </p>
            )}
          </AlertItem>
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
          void load(filters)
        }}
      >
        <div className="relative w-full max-w-xs">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search deal or account"
            className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          aria-label="Owner filter"
          value={owner}
          onChange={(event) => setOwner(event.target.value as OwnerFilter)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All owners</option>
          <option value="me">My deals</option>
          <option value="team">My team</option>
        </select>

        <select
          aria-label="Close window filter"
          value={closeWindow}
          onChange={(event) => setCloseWindow(event.target.value)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">Any close date</option>
          <option value="month">Closing this month</option>
          <option value="quarter">Closing this quarter</option>
          <option value="overdue">Overdue</option>
        </select>

        <button
          type="submit"
          className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
          Filter
        </button>
      </form>

      {loading && !board ? (
        <p className="text-sm text-muted-foreground">Loading the pipeline…</p>
      ) : columns.every((column) => column.count === 0) && closed.length === 0 ? (
        <EmptyState
          title="No deals yet"
          description="Open a deal on an account, or convert a qualified lead."
          action={
            <Link
              to="/opportunities/new"
              className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
            >
              <Plus className="h-4 w-4" aria-hidden="true" />
              New deal
            </Link>
          }
        />
      ) : view === 'table' ? (
        <DataTable
          columns={dealTableColumns}
          rows={tableDeals}
          rowKey={(deal) => deal.id}
          caption="Deals in the current pipeline view"
        />
      ) : view === 'funnel' ? (
        <PipelineFunnel columns={columns} detailed />
      ) : (
        <>
          <PipelineFunnel columns={columns} />

          <div className="flex items-start gap-4 overflow-x-auto pb-4">
            {openStageOrder.map((stage) => {
              const column = columns.find((entry) => entry.stage === stage)
              return (
                <BoardColumn
                  key={stage}
                  stage={stage}
                  count={column?.count ?? 0}
                  value={column?.value ?? 0}
                  weighted={column?.weighted_value ?? 0}
                  deals={column?.deals ?? []}
                  busyId={busyId}
                  onRequestMove={requestMove}
                  onDropDeal={(dealId) => dropMove(dealId, stage)}
                  onPeek={setPeek}
                />
              )
            })}

            <ClosedColumn
              won={won}
              lost={lost}
              busyId={busyId}
              onRequestMove={requestMove}
              onDropDeal={dropMove}
              onPeek={setPeek}
            />
          </div>
        </>
      )}

      <DealPeekDrawer deal={peek} onClose={() => setPeek(null)} />

      {pendingLost && (
        <LostReasonDialog
          dealName={pendingLost.name}
          busy={busyId === pendingLost.id}
          onCancel={() => setPendingLost(null)}
          onConfirm={(reason, note) => void submitMove(pendingLost, 'lost', { reason, note })}
        />
      )}

      {pendingWon && (
        <WonConfirmDialog
          dealName={pendingWon.name}
          canSetAmount={pendingWon.can_set_amount}
          amount={pendingWon.amount}
          busy={busyId === pendingWon.id}
          onCancel={() => setPendingWon(null)}
          onConfirm={(outcome) => void submitMove(pendingWon, 'won', { outcome })}
        />
      )}
    </div>
  )
}

interface BoardColumnProps {
  stage: OpportunityStage
  count: number
  value: number
  weighted: number
  deals: Opportunity[]
  busyId: string | null
  onRequestMove: (deal: Opportunity, stage: OpportunityStage) => void
  onDropDeal: (dealId: string) => void
  onPeek: (deal: Opportunity) => void
}

function BoardColumn({
  stage,
  count,
  value,
  weighted,
  deals,
  busyId,
  onRequestMove,
  onDropDeal,
  onPeek,
}: BoardColumnProps) {
  const [over, setOver] = useState(false)

  return (
    <section
      aria-label={`${opportunityStageMeta[stage].label} column`}
      onDragOver={(event) => {
        event.preventDefault()
        setOver(true)
      }}
      onDragLeave={() => setOver(false)}
      onDrop={(event) => {
        event.preventDefault()
        setOver(false)
        onDropDeal(event.dataTransfer.getData('text/plain'))
      }}
      className={`flex w-72 shrink-0 flex-col rounded-lg border bg-card/50 ${
        over ? 'border-primary' : 'border-border'
      }`}
    >
      <header className="border-b border-border px-3 py-2">
        <div className="flex items-center justify-between gap-2">
          <StatusBadge tone={opportunityStageMeta[stage].tone}>
            {opportunityStageMeta[stage].label}
          </StatusBadge>
          <span className="text-xs text-muted-foreground">{count}</span>
        </div>
        <p className="mt-1 text-xs text-muted-foreground">
          {formatCurrency(value)} · {formatCurrency(weighted)} weighted
        </p>
      </header>

      <div className="flex flex-col gap-2 p-2">
        {deals.length === 0 ? (
          <p className="px-1 py-4 text-center text-xs text-muted-foreground">No deals</p>
        ) : (
          deals.map((deal) => (
            <DealCard
              key={deal.id}
              deal={deal}
              busy={busyId === deal.id}
              onRequestMove={onRequestMove}
              onPeek={onPeek}
            />
          ))
        )}
      </div>
    </section>
  )
}

function ClosedColumn({
  won,
  lost,
  busyId,
  onRequestMove,
  onDropDeal,
  onPeek,
}: {
  won: Opportunity[]
  lost: Opportunity[]
  busyId: string | null
  onRequestMove: (deal: Opportunity, stage: OpportunityStage) => void
  onDropDeal: (dealId: string, stage: OpportunityStage) => void
  onPeek: (deal: Opportunity) => void
}) {
  return (
    <section aria-label="Closed deals column" className="flex w-72 shrink-0 flex-col gap-3">
      {(['won', 'lost'] as const).map((stage) => {
        const deals = stage === 'won' ? won : lost
        return (
          <div
            key={stage}
            onDragOver={(event) => event.preventDefault()}
            onDrop={(event) => {
              event.preventDefault()
              onDropDeal(event.dataTransfer.getData('text/plain'), stage)
            }}
            className="rounded-lg border border-border bg-card/50"
          >
            <header className="flex items-center justify-between border-b border-border px-3 py-2">
              <StatusBadge tone={opportunityStageMeta[stage].tone}>
                {opportunityStageMeta[stage].label}
              </StatusBadge>
              <span className="text-xs text-muted-foreground">{deals.length}</span>
            </header>
            <div className="flex flex-col gap-2 p-2">
              {deals.length === 0 ? (
                <p className="px-1 py-3 text-center text-xs text-muted-foreground">
                  Drag a deal here
                </p>
              ) : (
                deals.map((deal) => (
                  <DealCard
                    key={deal.id}
                    deal={deal}
                    busy={busyId === deal.id}
                    onRequestMove={onRequestMove}
                    onPeek={onPeek}
                  />
                ))
              )}
            </div>
          </div>
        )
      })}
    </section>
  )
}

function DealCard({
  deal,
  busy,
  onRequestMove,
  onPeek,
}: {
  deal: Opportunity
  busy: boolean
  onRequestMove: (deal: Opportunity, stage: OpportunityStage) => void
  onPeek: (deal: Opportunity) => void
}) {
  const openPeek = (target: EventTarget | null) => {
    if (target instanceof HTMLElement && target.closest('a,button,select,input,textarea')) return
    onPeek(deal)
  }

  return (
    <article
      draggable={deal.can_change_stage}
      tabIndex={0}
      aria-label={`Quick look: ${deal.name}`}
      onClick={(event) => openPeek(event.target)}
      onKeyDown={(event) => {
        if (event.target !== event.currentTarget) return
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault()
          onPeek(deal)
        }
      }}
      onDragStart={(event) => {
        event.dataTransfer.setData('text/plain', deal.id)
        event.dataTransfer.effectAllowed = 'move'
      }}
      className={`cursor-pointer rounded-lg border border-border bg-card p-3 shadow-sm outline-none focus:ring-2 focus:ring-ring ${busy ? 'opacity-60' : ''}`}
    >
      <div className="flex items-start justify-between gap-2">
        <Link
          to={`/opportunities/${deal.id}`}
          className="text-sm font-medium text-foreground hover:underline"
        >
          {deal.name}
        </Link>
        {deal.is_stale && <StatusBadge tone="warm">Stale</StatusBadge>}
      </div>

      <p className="mt-1 text-xs text-muted-foreground">
        {deal.company?.name ?? '—'} · <span className="font-mono">{deal.reference}</span>
      </p>

      {(deal.estimated_headcount || deal.service_type) && (
        <div className="mt-2">
          <StaffingBadge
            estimatedHeadcount={deal.estimated_headcount}
            serviceType={deal.service_type}
          />
        </div>
      )}

      {(deal.next_follow_up || (deal.is_open && followUpWarningStages.includes(deal.stage))) && (
        <div className="mt-2">
          <DealFollowUpBadge deal={deal} />
        </div>
      )}

      {/* ADR-0027 — a won deal surfaces its read-only contract projection, if any (spec 18 S3). */}
      {deal.is_won && deal.contracts && deal.contracts.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1">
          {deal.contracts.map((contract) => (
            <Link
              key={contract.id}
              to={`/contracts/${contract.id}`}
              className="inline-flex items-center rounded-full bg-people/15 px-2 py-0.5 text-xs font-medium text-people hover:underline"
            >
              {contract.reference} · {contractStatusMeta[contract.status].label}
            </Link>
          ))}
        </div>
      )}

      <p className="mt-2 text-sm font-semibold text-cost">{formatCurrency(deal.amount)}</p>

      <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
        <span>{deal.owner?.name ?? 'Unassigned'}</span>
        <span>{deal.probability ?? 0}%</span>
      </div>

      <div className="mt-1 flex items-center justify-between text-xs text-muted-foreground">
        <span>
          {deal.expected_close_date
            ? `Close ${formatDate(deal.expected_close_date)}`
            : 'No close date'}
        </span>
        <span>{deal.days_in_stage}d in stage</span>
      </div>

      {deal.next_step && <p className="mt-2 text-xs text-foreground">Next: {deal.next_step}</p>}

      <div className="mt-3">
        <StageMoveControl
          deal={deal}
          disabled={busy || !deal.can_change_stage}
          onRequestMove={(stage) => onRequestMove(deal, stage)}
        />
      </div>
    </article>
  )
}
