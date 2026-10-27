import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, MessagesSquare } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatCard from '../components/common/StatCard'
import FormCard from '../components/common/FormCard'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import BandSplit from '../components/common/BandSplit'
import { getSatisfactionSummary } from '../lib/satisfaction'
import {
  formatCsat,
  formatNps,
  formatNpsScore,
  formatRate,
  surveyBandMeta,
} from '../lib/surveyMeta'
import { formatDateTime } from '../lib/format'
import type { SatisfactionSummary, SurveyResponseSummary } from '../types'

/**
 * The satisfaction summary (module 05 slice S3, spec 13 §S3).
 *
 * Every number is derived from the responses the caller may see — a salesrep counts their own
 * accounts, a manager the team's, an admin everything — so two people can legitimately read
 * different CSAT figures. The cards lead into the response book, which is where the answers
 * themselves live.
 */
export default function Satisfaction() {
  const [summary, setSummary] = useState<SatisfactionSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      setSummary(await getSatisfactionSummary())
    } catch {
      setError('Could not load the satisfaction summary.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const columns: DataTableColumn<SurveyResponseSummary>[] = [
    {
      key: 'company',
      header: 'Account',
      render: (response) => (
        <div className="flex flex-col">
          <Link
            to={response.company ? `/accounts/${response.company.id}?tab=satisfaction` : '#'}
            className="font-medium text-foreground hover:text-primary"
          >
            {response.company?.name ?? '—'}
          </Link>
          <span className="text-xs text-muted-foreground">
            {response.contact?.name ?? 'No named contact'}
          </span>
        </div>
      ),
    },
    {
      key: 'survey',
      header: 'Survey',
      className: 'text-muted-foreground',
      render: (response) => response.survey?.name ?? '—',
    },
    {
      key: 'csat',
      header: 'CSAT',
      className: 'whitespace-nowrap',
      render: (response) => formatCsat(response.csat),
    },
    {
      key: 'nps',
      header: 'NPS',
      className: 'whitespace-nowrap',
      render: (response) => (
        <div className="flex items-center gap-2">
          <span>{formatNps(response.nps)}</span>
          {response.band && (
            <StatusBadge tone={surveyBandMeta[response.band].tone}>
              {surveyBandMeta[response.band].label}
            </StatusBadge>
          )}
        </div>
      ),
    },
    {
      key: 'attention',
      header: 'Follow-through',
      render: (response) =>
        response.resolved ? (
          <StatusBadge tone="success">Resolved</StatusBadge>
        ) : response.needs_attention ? (
          <StatusBadge tone="danger">Needs attention</StatusBadge>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: 'submitted_at',
      header: 'Answered',
      className: 'whitespace-nowrap text-muted-foreground',
      render: (response) => formatDateTime(response.submitted_at),
    },
    {
      key: 'actions',
      header: '',
      render: (response) => (
        <Link
          to={`/satisfaction/responses/${response.id}`}
          className="inline-flex items-center gap-1 text-xs font-medium text-counts"
        >
          Read
          <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
        </Link>
      ),
    },
  ]

  const attentionColumns: DataTableColumn<SurveyResponseSummary>[] = [
    {
      key: 'company',
      header: 'Account',
      render: (response) => (
        <div className="flex flex-col">
          <Link
            to={response.company ? `/accounts/${response.company.id}?tab=satisfaction` : '#'}
            className="font-medium text-foreground hover:text-primary"
          >
            {response.company?.name ?? '—'}
          </Link>
          <span className="text-xs text-muted-foreground">
            {response.contact?.name ?? 'No named contact'}
          </span>
        </div>
      ),
    },
    {
      key: 'survey',
      header: 'Survey',
      className: 'text-muted-foreground',
      render: (response) => response.survey?.name ?? '—',
    },
    {
      key: 'score',
      header: 'Score',
      className: 'whitespace-nowrap',
      render: (response) => (
        <span className="text-foreground">
          {formatCsat(response.csat)} · NPS {formatNps(response.nps)}
        </span>
      ),
    },
    {
      key: 'submitted_at',
      header: 'Answered',
      className: 'whitespace-nowrap text-muted-foreground',
      render: (response) => formatDateTime(response.submitted_at),
    },
    {
      key: 'actions',
      header: '',
      render: (response) => (
        <Link
          to={`/satisfaction/responses/${response.id}`}
          className="inline-flex items-center gap-1 whitespace-nowrap text-xs font-medium text-counts"
        >
          Read &amp; resolve
          <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
        </Link>
      ),
    },
  ]

  return (
    <div>
      <PageHeader
        title="Satisfaction"
        description="How clients rate the work after the sale — and who is waiting on a call back."
        actions={
          <Link
            to="/satisfaction/responses"
            className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
          >
            <MessagesSquare className="h-4 w-4" aria-hidden="true" />
            All responses
          </Link>
        }
      />

      {error && <AlertItem variant="danger">{error}</AlertItem>}

      {loading ? (
        <p className="text-sm text-muted-foreground">Loading the numbers…</p>
      ) : summary === null ? null : summary.responses === 0 ? (
        <EmptyState
          title="No answers yet"
          description="Send a survey from a client account and its answer lands here, scored and ready to read."
        />
      ) : (
        <div className="flex flex-col gap-6">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="CSAT · mean rating" value={formatCsat(summary.csat)} domain="people" />
            <StatCard
              label="Net promoter score"
              value={formatNpsScore(summary.nps)}
              domain="counts"
            />
            <StatCard
              label="Response rate"
              value={formatRate(summary.response_rate)}
              domain="activity"
            />
            <StatCard label="Needs attention" value={summary.attention} domain="cost" />
          </div>

          {(summary.attention_responses ?? []).length > 0 && (
            <FormCard
              title="Waiting on a call back"
              description="Unhappy answers nobody has closed yet. A note on the response is the record that somebody reached out."
            >
              <DataTable
                columns={attentionColumns}
                rows={summary.attention_responses}
                rowKey={(response) => response.id}
                empty={<EmptyState title="Nobody is waiting" />}
              />
            </FormCard>
          )}

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <FormCard
              title="Promoter split"
              description={`${summary.answered} ${
                summary.answered === 1 ? 'answer' : 'answers'
              } to the recommendation question`}
            >
              <BandSplit
                promoters={summary.promoters}
                passives={summary.passives}
                detractors={summary.detractors}
                answered={summary.answered}
              />
              <p className="mt-4 text-xs text-muted-foreground">
                {summary.responses} {summary.responses === 1 ? 'answer' : 'answers'} from{' '}
                {summary.invitations} {summary.invitations === 1 ? 'send' : 'sends'} that could be
                answered. A failed or withdrawn send is not counted against the rate.
              </p>
            </FormCard>

            <div className="lg:col-span-2">
              <FormCard
                title="Recent answers"
                description="Newest first. A row marked for attention is a client whose answer crossed the unhappy line."
              >
                <DataTable
                  columns={columns}
                  rows={summary.recent}
                  rowKey={(response) => response.id}
                  empty={<EmptyState title="Nothing to read yet" />}
                />
              </FormCard>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
