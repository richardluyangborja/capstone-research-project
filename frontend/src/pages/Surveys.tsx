import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ClipboardList, CopyPlus, RotateCcw, Search, Send, Trash2 } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import {
  duplicateSurvey,
  listSurveys,
  restoreSurvey,
  retireSurvey,
  type SurveyFilters,
} from '../lib/surveys'
import { surveyStateMeta, surveyStateOf, type SurveyState } from '../lib/surveyMeta'
import { formatDateTime } from '../lib/format'
import { useAuth } from '../lib/auth-context'
import type { Survey } from '../types'

// Module 05 §UI (slice S1) — the survey library. A survey is the house's wording: every role
// reads it, manager+ writes, duplicates, retires and restores. Sending it to a client is slice S2
// and hangs off the account, not off this book.
export default function Surveys() {
  const { user } = useAuth()
  const isManagerOrAbove = user?.role !== 'salesrep'

  const [surveys, setSurveys] = useState<Survey[]>([])
  const [search, setSearch] = useState('')
  const [state, setState] = useState<SurveyState | ''>('')
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
        const filters: SurveyFilters = {
          search: search || undefined,
          state,
          page: targetPage,
        }
        const response = await listSurveys(filters)
        setSurveys(response.data)
        setPage(response.meta.current_page)
        setLastPage(response.meta.last_page)
        setTotal(response.meta.total)
      } catch {
        setError('Could not load the surveys. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [search, state],
  )

  useEffect(() => {
    void load(1)
  }, [load])

  const onDuplicate = async (survey: Survey) => {
    setSaving(survey.id)
    setError('')
    setNotice('')
    try {
      const copy = await duplicateSurvey(survey.id)
      setNotice(`Copied into “${copy.name}” — it starts as a draft; edit it and activate when ready.`)
      await load(page)
    } catch {
      setError('Could not duplicate that survey.')
    } finally {
      setSaving('')
    }
  }

  const onRetire = async (survey: Survey) => {
    setSaving(survey.id)
    setError('')
    setNotice('')
    try {
      await retireSurvey(survey.id)
      setNotice(`“${survey.name}” retired — it can no longer be sent; what it gathered stays.`)
      await load(page)
    } catch {
      setError('Could not retire that survey.')
    } finally {
      setSaving('')
    }
  }

  const onRestore = async (survey: Survey) => {
    setSaving(survey.id)
    setError('')
    setNotice('')
    try {
      await restoreSurvey(survey.id)
      setNotice(`“${survey.name}” is back in the library.`)
      await load(page)
    } catch {
      // The API refuses when a live survey has taken the name since (spec 13 §Business rules 11).
      setError(
        'Could not restore that survey — another survey may already use its name. Rename the live one first.',
      )
    } finally {
      setSaving('')
    }
  }

  const columns: DataTableColumn<Survey>[] = [
    {
      key: 'name',
      header: 'Survey',
      render: (survey) => {
        const surveyState = surveyStateOf(survey)
        return (
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              {survey.can_update ? (
                <Link to={`/surveys/${survey.id}`} className="font-medium text-counts">
                  {survey.name}
                </Link>
              ) : (
                <span className="font-medium text-foreground">{survey.name}</span>
              )}
              <StatusBadge tone={surveyStateMeta[surveyState].tone}>
                {surveyStateMeta[surveyState].label}
              </StatusBadge>
            </div>
            {survey.description && (
              <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                {survey.description}
              </p>
            )}
          </div>
        )
      },
    },
    {
      key: 'questions',
      header: 'Questions',
      className: 'whitespace-nowrap text-muted-foreground',
      render: (survey) => (
        <span>
          {survey.questions_count ?? 0} {survey.questions_count === 1 ? 'question' : 'questions'}
        </span>
      ),
    },
    {
      key: 'invitations',
      header: 'Sent',
      className: 'whitespace-nowrap text-muted-foreground',
      render: (survey) =>
        survey.invitations_count === undefined
          ? '—'
          : `${survey.invitations_count} ${survey.invitations_count === 1 ? 'send' : 'sends'}`,
    },
    {
      key: 'responses',
      header: 'Answered',
      className: 'whitespace-nowrap text-muted-foreground',
      render: (survey) =>
        survey.responses_count === undefined
          ? '—'
          : `${survey.responses_count} ${
              survey.responses_count === 1 ? 'answer' : 'answers'
            }`,
    },
    {
      key: 'author',
      header: 'Author',
      className: 'text-muted-foreground',
      render: (survey) => survey.author?.name ?? '—',
    },
    {
      key: 'updated_at',
      header: 'Updated',
      className: 'whitespace-nowrap text-muted-foreground',
      render: (survey) => formatDateTime(survey.updated_at),
    },
    {
      key: 'actions',
      header: '',
      render: (survey) => {
        const surveyState = surveyStateOf(survey)
        return (
          <div className="flex items-center justify-end gap-3">
            {surveyState !== 'retired' && survey.can_duplicate && (
              <button
                type="button"
                onClick={() => void onDuplicate(survey)}
                disabled={saving === survey.id}
                className="inline-flex items-center gap-1 text-xs font-medium text-foreground disabled:opacity-60"
              >
                <CopyPlus className="h-3.5 w-3.5" aria-hidden="true" />
                Duplicate
              </button>
            )}
            {surveyState === 'retired' && survey.can_restore && (
              <button
                type="button"
                onClick={() => void onRestore(survey)}
                disabled={saving === survey.id}
                className="inline-flex items-center gap-1 text-xs font-medium text-counts disabled:opacity-60"
              >
                <RotateCcw className="h-3.5 w-3.5" aria-hidden="true" />
                Restore
              </button>
            )}
            {surveyState !== 'retired' && survey.can_delete && (
              <button
                type="button"
                onClick={() => void onRetire(survey)}
                disabled={saving === survey.id}
                className="inline-flex items-center gap-1 text-xs font-medium text-destructive disabled:opacity-60"
              >
                <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                Retire
              </button>
            )}
          </div>
        )
      },
    },
  ]

  return (
    <div>
      <PageHeader
        title="Surveys"
        description="The questions we ask a client after a deployment, at a review, or at the end — sent as a link, answered without an account."
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Link
              to="/survey-invitations"
              className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
            >
              <Send className="h-4 w-4" aria-hidden="true" />
              Invitations
            </Link>
            {isManagerOrAbove && (
              <Link
                to="/surveys/new"
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
              >
                <ClipboardList className="h-4 w-4" aria-hidden="true" />
                New survey
              </Link>
            )}
          </div>
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
            placeholder="Search the name or the description"
            className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          aria-label="State filter"
          value={state}
          onChange={(event) => setState(event.target.value as SurveyState | '')}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All states</option>
          <option value="active">Active — can be sent</option>
          <option value="inactive">Draft — still being written</option>
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
        rows={surveys}
        rowKey={(survey) => survey.id}
        loading={loading}
        loadingLabel="Loading surveys…"
        pagination={{
          currentPage: page,
          lastPage,
          total,
          onPageChange: (next) => void load(next),
        }}
        empty={
          <EmptyState
            title="No surveys yet"
            description={
              isManagerOrAbove
                ? 'Write the questions once, then send the survey to a client when the moment comes.'
                : 'A manager has not written a survey yet.'
            }
            action={
              isManagerOrAbove ? (
                <Link
                  to="/surveys/new"
                  className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
                >
                  <ClipboardList className="h-4 w-4" aria-hidden="true" />
                  New survey
                </Link>
              ) : undefined
            }
          />
        }
      />
    </div>
  )
}
