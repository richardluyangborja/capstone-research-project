import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ArrowRight, Gauge, Search, X } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import { listSurveyResponses } from '../lib/satisfaction'
import { listSurveys } from '../lib/surveys'
import { formatCsat, formatNps, surveyBandMeta, surveyBandNames } from '../lib/surveyMeta'
import { formatDateTime } from '../lib/format'
import type { Survey, SurveyBandName, SurveyResponseFilters, SurveyResponseSummary } from '../types'

/**
 * The response book (module 05 slice S3, spec 13 §S3): every answer in view, newest first.
 *
 * A row names the account, who answered, the score and whether it crossed the unhappy line; the
 * detail is where the questions are read back. The account card deep-links here with `?company=`,
 * so one account's answers can be read without a company picker.
 */
export default function SurveyResponses() {
  const [searchParams] = useSearchParams()
  const [company] = useState(searchParams.get('company') ?? '')

  const [responses, setResponses] = useState<SurveyResponseSummary[]>([])
  const [surveys, setSurveys] = useState<Survey[]>([])
  const [search, setSearch] = useState('')
  const [survey, setSurvey] = useState(searchParams.get('survey') ?? '')
  const [band, setBand] = useState<SurveyBandName | ''>(
    (searchParams.get('band') as SurveyBandName | null) ?? '',
  )
  const [attention, setAttention] = useState<'true' | 'false' | ''>(
    searchParams.get('attention') === 'true'
      ? 'true'
      : searchParams.get('attention') === 'false'
        ? 'false'
        : '',
  )
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
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
        const filters: SurveyResponseFilters = {
          search: search || undefined,
          survey: survey || undefined,
          company: company || undefined,
          band,
          attention,
          date_from: dateFrom || undefined,
          date_to: dateTo || undefined,
          page: targetPage,
        }
        const response = await listSurveyResponses(filters)
        setResponses(response.data)
        setPage(response.meta.current_page)
        setLastPage(response.meta.last_page)
        setTotal(response.meta.total)
      } catch {
        setError('Could not load the responses. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [search, survey, company, band, attention, dateFrom, dateTo],
  )

  useEffect(() => {
    void load(1)
  }, [load])

  useEffect(() => {
    void (async () => {
      try {
        const response = await listSurveys({})
        setSurveys(response.data)
      } catch {
        // The book still works without the survey picker.
      }
    })()
  }, [])

  const columns: DataTableColumn<SurveyResponseSummary>[] = [
    {
      key: 'company',
      header: 'Account',
      render: (response) => (
        <div className="min-w-0">
          <Link
            to={response.company ? `/companies/${response.company.id}?tab=satisfaction` : '#'}
            className="font-medium text-foreground hover:text-primary"
          >
            {response.company?.name ?? '—'}
          </Link>
          <p className="text-xs text-muted-foreground">
            {response.contact?.name ?? 'No named contact'}
          </p>
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
        response.needs_attention ? (
          <StatusBadge tone="danger">Needs attention</StatusBadge>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: 'invitation',
      header: 'Sent as',
      className: 'whitespace-nowrap font-mono text-xs text-muted-foreground',
      render: (response) => response.invitation?.reference ?? '—',
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

  return (
    <div>
      <PageHeader
        title="Responses"
        description="Every answer the survey library has gathered, scoped to the accounts you can see."
        actions={
          <Link
            to="/satisfaction"
            className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
          >
            <Gauge className="h-4 w-4" aria-hidden="true" />
            Summary
          </Link>
        }
      />

      {error && (
        <div className="mb-4">
          <AlertItem variant="danger">{error}</AlertItem>
        </div>
      )}

      {company && (
        <div className="mb-4">
          <AlertItem variant="info">
            Showing one account’s answers.{' '}
            <Link
              to="/satisfaction/responses"
              className="inline-flex items-center gap-1 font-medium"
            >
              <X className="h-3.5 w-3.5" aria-hidden="true" />
              Read every account
            </Link>
          </AlertItem>
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
            placeholder="Search the account, contact, survey or send"
            className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          value={survey}
          onChange={(event) => setSurvey(event.target.value)}
          aria-label="Filter by survey"
          className="max-w-xs rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">Every survey</option>
          {surveys.map((entry) => (
            <option key={entry.id} value={entry.id}>
              {entry.name}
            </option>
          ))}
        </select>

        <select
          value={band}
          onChange={(event) => setBand(event.target.value as SurveyBandName | '')}
          aria-label="Filter by band"
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">Every band</option>
          {surveyBandNames.map((value) => (
            <option key={value} value={value}>
              {surveyBandMeta[value].label}
            </option>
          ))}
        </select>

        <select
          value={attention}
          onChange={(event) => setAttention(event.target.value as 'true' | 'false' | '')}
          aria-label="Filter by follow-through"
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">Every answer</option>
          <option value="true">Needs attention</option>
          <option value="false">Settled</option>
        </select>

        <label className="flex items-center gap-2 text-xs text-muted-foreground">
          From
          <input
            type="date"
            value={dateFrom}
            onChange={(event) => setDateFrom(event.target.value)}
            aria-label="Answered from"
            className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </label>

        <label className="flex items-center gap-2 text-xs text-muted-foreground">
          To
          <input
            type="date"
            value={dateTo}
            onChange={(event) => setDateTo(event.target.value)}
            aria-label="Answered to"
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

      <DataTable
        columns={columns}
        rows={responses}
        rowKey={(response) => response.id}
        loading={loading}
        loadingLabel="Loading responses…"
        pagination={{
          currentPage: page,
          lastPage,
          total,
          onPageChange: (next) => void load(next),
        }}
        empty={
          <EmptyState
            title="No answers match"
            description="Adjust the filters, or send a survey from an account's Satisfaction tab."
            action={
              <Link
                to="/surveys"
                className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
              >
                Survey library
              </Link>
            }
          />
        }
      />
    </div>
  )
}
