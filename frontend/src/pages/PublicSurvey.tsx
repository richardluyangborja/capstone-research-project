import { useEffect, useState, type FormEvent } from 'react'
import { useParams } from 'react-router-dom'
import axios from 'axios'
import { CheckCircle2, Handshake, Loader2 } from 'lucide-react'
import { getPublicSurvey, submitPublicSurvey } from '../lib/publicSurvey'
import type {
  PublicSurvey,
  PublicSurveyAnswerInput,
  PublicSurveyQuestion,
  SurveyResponseSummary,
} from '../types'

type AnswerDraft = { number?: number; text?: string; options?: string[] }

/**
 * The client's page (module 05 slice S2, spec 13 §UI). No login, no dashboard shell: the link's
 * token is the credential, and the page shows the survey being asked and nothing about the account
 * beyond the greeting. A dead link says why; a submission becomes a thank-you and the token dies.
 */
export default function PublicSurvey() {
  const { token = '' } = useParams<{ token: string }>()

  const [survey, setSurvey] = useState<PublicSurvey | null>(null)
  const [answers, setAnswers] = useState<Record<string, AnswerDraft>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [receipt, setReceipt] = useState<SurveyResponseSummary | null>(null)

  useEffect(() => {
    void (async () => {
      try {
        setSurvey(await getPublicSurvey(token))
      } catch (error) {
        setMessage(readError(error))
      } finally {
        setLoading(false)
      }
    })()
  }, [token])

  const setAnswer = (questionId: string, patch: AnswerDraft) => {
    setAnswers((current) => ({ ...current, [questionId]: { ...current[questionId], ...patch } }))
  }

  const toggleOption = (question: PublicSurveyQuestion, option: string) => {
    const current = answers[question.id]?.options ?? []

    if (question.type === 'single_choice') {
      setAnswer(question.id, { options: [option] })
      return
    }

    setAnswer(question.id, {
      options: current.includes(option)
        ? current.filter((value) => value !== option)
        : [...current, option],
    })
  }

  const answerPayload = (question: PublicSurveyQuestion): PublicSurveyAnswerInput | null => {
    const draft = answers[question.id]

    if (!draft) return null

    if (question.bounds !== null) {
      return draft.number === undefined
        ? null
        : { question_id: question.id, value_number: draft.number }
    }

    if (question.type === 'text') {
      const text = (draft.text ?? '').trim()
      return text === '' ? null : { question_id: question.id, value_text: text }
    }

    const options = draft.options ?? []
    return options.length === 0 ? null : { question_id: question.id, value_options: options }
  }

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()

    if (!survey) return

    setMessage('')
    setErrors({})

    // The server is the authority (it knows what is required); this only saves a round trip on a
    // blank required question.
    const missing: Record<string, string[]> = {}
    for (const question of survey.survey.questions) {
      if (question.required && answerPayload(question) === null) {
        missing[`answers.${question.id}`] = ['Please answer this question.']
      }
    }
    if (Object.keys(missing).length > 0) {
      setErrors(missing)
      setMessage('Please answer the questions marked as required.')
      return
    }

    const payload = survey.survey.questions
      .map(answerPayload)
      .filter((answer): answer is PublicSurveyAnswerInput => answer !== null)

    setSubmitting(true)
    try {
      setReceipt(await submitPublicSurvey(token, payload))
    } catch (error) {
      const validation = axios.isAxiosError(error) ? error.response?.data?.errors : undefined
      if (validation && typeof validation === 'object') {
        setErrors(validation as Record<string, string[]>)
        setMessage('Some answers need another look.')
      } else {
        setMessage(readError(error))
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="min-h-screen bg-background px-4 py-10">
      <div className="mx-auto w-full max-w-2xl">
        <header className="mb-6 flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
            <Handshake className="h-5 w-5" aria-hidden="true" />
          </span>
          <div>
            <p className="text-sm font-semibold leading-tight text-foreground">
              <span className="text-logo-brand">Primepower</span> Manpower Services
            </p>
            <p className="text-xs text-muted-foreground">Customer satisfaction survey</p>
          </div>
        </header>

        <div className="rounded-xl border border-border bg-card p-6 shadow-sm">
          {loading && (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
              Opening your survey…
            </p>
          )}

          {!loading && receipt !== null && <ThankYou receipt={receipt} survey={survey} />}

          {!loading && receipt === null && survey === null && (
            <div className="flex flex-col gap-2">
              <h1 className="text-lg font-semibold text-foreground">This link cannot be used</h1>
              <p className="text-sm text-muted-foreground">{message}</p>
            </div>
          )}

          {!loading && receipt === null && survey !== null && (
            <form className="flex flex-col gap-6" onSubmit={(event) => void onSubmit(event)}>
              <div>
                <h1 className="text-xl font-semibold text-foreground">{survey.survey.name}</h1>
                {survey.survey.description && (
                  <p className="mt-1 text-sm text-muted-foreground">{survey.survey.description}</p>
                )}
                <p className="mt-3 text-sm text-foreground">
                  {greeting(survey)}
                </p>
              </div>

              {message && (
                <p className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                  {message}
                </p>
              )}

              <ol className="flex flex-col gap-6">
                {survey.survey.questions.map((question, index) => (
                  <li key={question.id}>
                    <QuestionField
                      index={index}
                      question={question}
                      draft={answers[question.id] ?? {}}
                      errors={errors[`answers.${question.id}`]}
                      onNumber={(number) => setAnswer(question.id, { number })}
                      onText={(text) => setAnswer(question.id, { text })}
                      onOption={(option) => toggleOption(question, option)}
                    />
                  </li>
                ))}
              </ol>

              <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                <p className="text-xs text-muted-foreground">
                  {survey.expires_at
                    ? `This link is yours alone and closes on ${formatDay(survey.expires_at)}.`
                    : 'This link is yours alone.'}
                </p>
                <button
                  type="submit"
                  disabled={submitting}
                  className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
                >
                  {submitting && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
                  Send my answers
                </button>
              </div>
            </form>
          )}
        </div>

        <p className="mt-6 text-center text-xs text-muted-foreground">
          Sent by Primepower Manpower Services. If you were not expecting this, you can ignore it.
        </p>
      </div>
    </div>
  )
}

function QuestionField({
  index,
  question,
  draft,
  errors,
  onNumber,
  onText,
  onOption,
}: {
  index: number
  question: PublicSurveyQuestion
  draft: AnswerDraft
  errors?: string[]
  onNumber: (value: number) => void
  onText: (value: string) => void
  onOption: (value: string) => void
}) {
  const scale = question.bounds

  return (
    <div className="flex flex-col gap-2">
      <p className="text-sm font-medium text-foreground">
        <span className="mr-1 text-muted-foreground">{index + 1}.</span>
        {question.prompt}
        {question.required && <span className="ml-1 text-destructive">*</span>}
      </p>
      {question.help && <p className="text-xs text-muted-foreground">{question.help}</p>}

      {scale !== null && (
        <div className="flex flex-wrap gap-1.5">
          {Array.from({ length: scale.max - scale.min + 1 }, (_, offset) => scale.min + offset).map(
            (value) => {
              const selected = draft.number === value
              return (
                <button
                  key={value}
                  type="button"
                  aria-pressed={selected}
                  onClick={() => onNumber(value)}
                  className={`h-9 w-9 rounded-md border text-sm font-medium transition-colors ${
                    selected
                      ? 'border-primary bg-primary text-primary-foreground'
                      : 'border-input bg-card text-foreground hover:bg-muted'
                  }`}
                >
                  {value}
                </button>
              )
            },
          )}
          <span className="self-center pl-1 text-xs text-muted-foreground">
            {question.type === 'nps'
              ? `${scale.min} = not at all likely · ${scale.max} = extremely likely`
              : `${scale.min} = very dissatisfied · ${scale.max} = very satisfied`}
          </span>
        </div>
      )}

      {(question.type === 'single_choice' || question.type === 'multi_choice') && (
        <div className="flex flex-col gap-1.5">
          {question.options.map((option) => {
            const selected = (draft.options ?? []).includes(option)
            return (
              <label
                key={option}
                className={`flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm ${
                  selected ? 'border-primary bg-primary/5 text-foreground' : 'border-input text-foreground'
                }`}
              >
                <input
                  type={question.type === 'single_choice' ? 'radio' : 'checkbox'}
                  name={question.id}
                  checked={selected}
                  onChange={() => onOption(option)}
                  className="h-4 w-4"
                />
                {option}
              </label>
            )
          })}
        </div>
      )}

      {question.type === 'text' && (
        <textarea
          rows={4}
          value={draft.text ?? ''}
          onChange={(event) => onText(event.target.value)}
          placeholder="Your answer"
          className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        />
      )}

      {errors && errors.length > 0 && (
        <p className="text-xs text-destructive">{errors.join(' ')}</p>
      )}
    </div>
  )
}

function ThankYou({
  receipt,
  survey,
}: {
  receipt: SurveyResponseSummary
  survey: PublicSurvey | null
}) {
  return (
    <div className="flex flex-col items-center gap-3 py-6 text-center">
      <CheckCircle2 className="h-10 w-10 text-success" aria-hidden="true" />
      <h1 className="text-xl font-semibold text-foreground">Thank you — your answers are in.</h1>
      <p className="max-w-md text-sm text-muted-foreground">
        {survey ? `We have passed them to the team that looks after ${survey.company.name}. ` : ''}
        If anything needs a follow-up, somebody will be in touch.
      </p>
      <p className="text-xs text-muted-foreground">
        Submitted {formatMoment(receipt.submitted_at)}
      </p>
    </div>
  )
}

function greeting(survey: PublicSurvey): string {
  const firstName = survey.contact?.name.split(' ')[0]

  return firstName
    ? `Hi ${firstName}, thanks for taking a moment to tell us how ${survey.company.name} is going.`
    : `Thanks for taking a moment to tell us how things are going.`
}

function formatDay(value: string): string {
  return new Date(value).toLocaleDateString('en-PH', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

function formatMoment(value: string | null): string {
  if (!value) return 'just now'

  return new Date(value).toLocaleString('en-PH', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function readError(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const status = error.response?.status
    const detail = (error.response?.data as { message?: string } | undefined)?.message

    if (status === 404) return 'That link is not valid. Check the address, or ask us for a new one.'
    if (status === 410) return detail ?? 'This survey link can no longer be used.'
    if (status === 429) return 'Too many attempts — please wait a moment and try again.'
    if (detail) return detail
  }

  return 'Could not reach the survey. Please try again in a moment.'
}
