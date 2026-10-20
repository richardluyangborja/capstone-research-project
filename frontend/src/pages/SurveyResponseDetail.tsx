import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, MessagesSquare, TriangleAlert } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import StatusBadge from '../components/common/StatusBadge'
import AlertItem from '../components/common/AlertItem'
import { getSurveyResponse } from '../lib/satisfaction'
import { formatCsat, formatNps, surveyBandMeta } from '../lib/surveyMeta'
import { formatDateTime } from '../lib/format'
import type { SurveyAnswer, SurveyResponseSummary } from '../types'

/** How one answer reads back, by the question it answered (module 05 slice S3). */
const answerText = (answer: SurveyAnswer): string => {
  if (answer.value_text) return answer.value_text
  if (answer.value_options?.length) return answer.value_options.join(', ')
  if (answer.value_number !== null) {
    return answer.question?.type === 'nps'
      ? `${answer.value_number} / 10`
      : `${answer.value_number} / 5`
  }

  return 'No answer'
}

/**
 * One answer set, read back in the order the survey asked (module 05 slice S3, spec 13 §S3).
 *
 * The score block says what the numbers mean; the questions below say what the client actually
 * said, in their own words where the survey asked for them. An answer marked for attention is
 * flagged here — the call-back itself is slice S4's work.
 */
export default function SurveyResponseDetail() {
  const { id } = useParams<{ id: string }>()
  const [response, setResponse] = useState<SurveyResponseSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    if (!id) return
    setLoading(true)
    setError('')
    try {
      setResponse(await getSurveyResponse(id))
    } catch {
      setError('Could not open that answer — it may belong to an account you cannot see.')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    void load()
  }, [load])

  if (loading) {
    return <p className="text-sm text-muted-foreground">Reading the answer…</p>
  }

  if (error || response === null) {
    return (
      <div>
        <AlertItem variant="danger">{error || 'That answer does not exist.'}</AlertItem>
        <Link
          to="/satisfaction/responses"
          className="mt-4 inline-flex items-center gap-2 text-sm font-medium text-counts"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden="true" />
          Back to the responses
        </Link>
      </div>
    )
  }

  return (
    <div>
      <PageHeader
        title={response.company?.name ?? 'Survey answer'}
        description={`${response.survey?.name ?? 'Survey'} · answered ${formatDateTime(
          response.submitted_at,
        )}`}
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

      {response.needs_attention && (
        <div className="mb-4">
          <AlertItem variant="warning" title="This client needs a call back">
            <span className="inline-flex items-center gap-2">
              <TriangleAlert className="h-4 w-4" aria-hidden="true" />
              The answer crossed the unhappy line (CSAT at or below 3, or a detractor score). The
              follow-up becomes a task on the account in the next slice; until then, read it and
              reach out.
            </span>
          </AlertItem>
        </div>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <FormCard
            title="The answers"
            description="In the order the survey asked, with the question as the client read it."
          >
            {(response.answers ?? []).length === 0 ? (
              <p className="text-sm text-muted-foreground">This answer carried no answers.</p>
            ) : (
              <ol className="flex flex-col gap-4">
                {(response.answers ?? []).map((answer, index) => (
                  <li key={answer.id} className="border-b border-border pb-4 last:border-0 last:pb-0">
                    <p className="text-sm font-medium text-foreground">
                      {index + 1}. {answer.question?.prompt ?? 'Question'}
                    </p>
                    <p className="mt-1 whitespace-pre-wrap text-sm text-muted-foreground">
                      {answerText(answer)}
                    </p>
                  </li>
                ))}
              </ol>
            )}
          </FormCard>
        </div>

        <div className="flex flex-col gap-6">
          <FormCard title="The score">
            <dl className="flex flex-col gap-3 text-sm">
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted-foreground">CSAT</dt>
                <dd className="font-medium text-foreground">{formatCsat(response.csat)}</dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted-foreground">NPS answer</dt>
                <dd className="font-medium text-foreground">{formatNps(response.nps)} / 10</dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted-foreground">Band</dt>
                <dd>
                  {response.band ? (
                    <StatusBadge tone={surveyBandMeta[response.band].tone}>
                      {surveyBandMeta[response.band].label}
                    </StatusBadge>
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </dd>
              </div>
            </dl>
          </FormCard>

          <FormCard title="The send">
            <dl className="flex flex-col gap-3 text-sm">
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted-foreground">Contact</dt>
                <dd className="text-foreground">{response.contact?.name ?? '—'}</dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted-foreground">Invitation</dt>
                <dd className="font-mono text-xs text-muted-foreground">
                  {response.invitation?.reference ?? '—'}
                </dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted-foreground">Answered</dt>
                <dd className="text-foreground">{formatDateTime(response.submitted_at)}</dd>
              </div>
            </dl>

            {response.company && (
              <Link
                to={`/companies/${response.company.id}?tab=satisfaction`}
                className="mt-4 inline-flex items-center gap-1 text-xs font-medium text-counts"
              >
                <ArrowLeft className="h-3.5 w-3.5 rotate-180" aria-hidden="true" />
                Back to the account
              </Link>
            )}
          </FormCard>
        </div>
      </div>
    </div>
  )
}
