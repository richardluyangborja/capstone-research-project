import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowDown, ArrowLeft, ArrowUp, Plus, Save, Trash2 } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import AlertItem from '../components/common/AlertItem'
import { createSurvey, getSurvey, updateSurvey } from '../lib/surveys'
import { surveyQuestionTypeMeta, surveyQuestionTypes } from '../lib/surveyMeta'
import { parseValidationError } from '../lib/leads'
import type { Survey, SurveyQuestionInput, SurveyQuestionTypeName } from '../types'

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

/** One question while it is being written — the server numbers them, the builder only orders. */
interface QuestionDraft {
  key: string
  type: SurveyQuestionTypeName
  prompt: string
  help: string
  required: boolean
  options: string[]
}

let draftCounter = 0
const draft = (type: SurveyQuestionTypeName = 'rating'): QuestionDraft => ({
  key: `question-${++draftCounter}`,
  type,
  prompt: '',
  help: '',
  required: true,
  options: surveyQuestionTypeMeta[type].hasOptions ? ['', ''] : [],
})

// Module 05 §UI (slice S1) — authoring a survey. Manager+ only (the API refuses a salesrep).
// The builder is the question set: the order on screen is the order the client is asked, and a
// choice question cannot leave without its options.
export default function SurveyForm() {
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const editing = Boolean(id)

  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [isActive, setIsActive] = useState(false)
  const [questions, setQuestions] = useState<QuestionDraft[]>([draft()])

  const [retired, setRetired] = useState(false)
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(editing)
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (!id) return
    void (async () => {
      try {
        const survey: Survey = await getSurvey(id)
        setName(survey.name)
        setDescription(survey.description ?? '')
        setIsActive(survey.is_active)
        setQuestions(
          (survey.questions ?? []).map((question) => ({
            key: question.id,
            type: question.type.value,
            prompt: question.prompt,
            help: question.help ?? '',
            required: question.required,
            options: question.type.value === 'single_choice' || question.type.value === 'multi_choice'
              ? [...question.options]
              : [],
          })),
        )
        setRetired(survey.deleted_at !== null)
      } catch {
        setMessage(
          'Could not load that survey. It may have been retired — look it up in the library and restore it first.',
        )
      } finally {
        setLoading(false)
      }
    })()
  }, [id])

  const updateQuestion = (key: string, patch: Partial<QuestionDraft>) => {
    setQuestions((current) =>
      current.map((question) => (question.key === key ? { ...question, ...patch } : question)),
    )
  }

  const changeType = (question: QuestionDraft, type: SurveyQuestionTypeName) => {
    // A choice question arrives with two empty options to fill; any other type drops them, because
    // the API refuses options on a rating, NPS or text question.
    updateQuestion(question.key, {
      type,
      options: surveyQuestionTypeMeta[type].hasOptions
        ? question.options.length >= 2
          ? question.options
          : ['', '']
        : [],
    })
  }

  const move = (index: number, direction: -1 | 1) => {
    setQuestions((current) => {
      const target = index + direction
      if (target < 0 || target >= current.length) return current
      const next = [...current]
      ;[next[index], next[target]] = [next[target], next[index]]
      return next
    })
  }

  const removeQuestion = (key: string) => {
    setQuestions((current) => current.filter((question) => question.key !== key))
  }

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setMessage('')
    setErrors({})

    const payload = {
      name: name.trim(),
      description: description.trim() || null,
      is_active: isActive,
      questions: questions.map((question): SurveyQuestionInput => {
        const input: SurveyQuestionInput = {
          type: question.type,
          prompt: question.prompt.trim(),
          help: question.help.trim() || null,
          required: question.required,
        }
        if (surveyQuestionTypeMeta[question.type].hasOptions) {
          input.options = question.options.map((option) => option.trim()).filter(Boolean)
        }
        return input
      }),
    }

    setSaving(true)
    try {
      if (id) {
        await updateSurvey(id, payload)
      } else {
        await createSurvey(payload)
      }
      navigate('/surveys')
    } catch (error) {
      const validation = parseValidationError(error)
      if (validation) {
        setErrors(validation.errors)
        setMessage(validation.message)
      } else {
        setMessage('Could not save the survey.')
      }
    } finally {
      setSaving(false)
    }
  }

  const questionError = (index: number, field: string): string[] | undefined =>
    errors[`questions.${index}.${field}`]

  return (
    <div>
      <PageHeader
        title={editing ? 'Edit survey' : 'New survey'}
        description="The questions a client answers through a link — written once, sent to as many accounts as you like."
        actions={
          <Link
            to="/surveys"
            className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
            Survey library
          </Link>
        }
      />

      {message && (
        <div className="mb-4">
          <AlertItem variant="danger">
            <p>{message}</p>
            {Object.entries(errors).map(([field, messages]) => (
              <p key={field} className="text-xs">
                {messages.join(' ')}
              </p>
            ))}
          </AlertItem>
        </div>
      )}

      {retired && (
        <div className="mb-4">
          <AlertItem variant="danger">
            This survey is retired, so it can no longer be sent. Restore it from the library first.
          </AlertItem>
        </div>
      )}

      <form onSubmit={onSubmit} className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div>
          <FormCard title="The survey">
            <Field label="Name" error={errors.name}>
              <input
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="Post-deployment check-in"
                disabled={loading || retired}
                className={inputClass}
              />
            </Field>

            <div className="mt-4">
              <Field label="Description" error={errors.description}>
                <textarea
                  value={description}
                  onChange={(event) => setDescription(event.target.value)}
                  rows={2}
                  placeholder="Sent after the first weeks of a deployment — what the page says above the questions."
                  disabled={loading || retired}
                  className={inputClass}
                />
              </Field>
            </div>

            <label className="mt-4 flex items-center gap-2 text-sm font-medium text-foreground">
              <input
                type="checkbox"
                checked={isActive}
                onChange={(event) => setIsActive(event.target.checked)}
                disabled={loading || retired}
                className="h-4 w-4 rounded border-input"
              />
              Active — can be sent to a client
            </label>
            <p className="mt-1 text-xs text-muted-foreground">
              A draft stays in the library while you finish writing it; only an active survey with
              at least one question can be sent.
            </p>
          </FormCard>

          <div className="mt-6">
            <FormCard
              title="Questions"
              description="The order here is the order the client is asked. Once the survey has been sent its questions are frozen — duplicate it to change them."
            >
              {errors.questions && (
                <p className="mb-3 text-sm text-destructive">{errors.questions.join(' ')}</p>
              )}

              {questions.length === 0 && (
                <p className="text-sm text-muted-foreground">
                  No questions yet — add one, or the survey cannot be activated.
                </p>
              )}

              <ul className="flex flex-col gap-4">
                {questions.map((question, index) => (
                  <li
                    key={question.key}
                    className="rounded-lg border border-border bg-card p-4"
                  >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="text-sm font-semibold text-foreground">
                        Question {index + 1}
                      </p>
                      <div className="flex items-center gap-1">
                        <button
                          type="button"
                          onClick={() => move(index, -1)}
                          disabled={index === 0 || retired}
                          aria-label={`Move question ${index + 1} up`}
                          className="rounded-md p-1 text-muted-foreground hover:bg-muted disabled:opacity-40"
                        >
                          <ArrowUp className="h-4 w-4" aria-hidden="true" />
                        </button>
                        <button
                          type="button"
                          onClick={() => move(index, 1)}
                          disabled={index === questions.length - 1 || retired}
                          aria-label={`Move question ${index + 1} down`}
                          className="rounded-md p-1 text-muted-foreground hover:bg-muted disabled:opacity-40"
                        >
                          <ArrowDown className="h-4 w-4" aria-hidden="true" />
                        </button>
                        <button
                          type="button"
                          onClick={() => removeQuestion(question.key)}
                          disabled={retired}
                          aria-label={`Remove question ${index + 1}`}
                          className="rounded-md p-1 text-destructive hover:bg-muted disabled:opacity-40"
                        >
                          <Trash2 className="h-4 w-4" aria-hidden="true" />
                        </button>
                      </div>
                    </div>

                    <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                      <Field label="Type" error={questionError(index, 'type')}>
                        <select
                          value={question.type}
                          onChange={(event) =>
                            changeType(question, event.target.value as SurveyQuestionTypeName)
                          }
                          disabled={retired}
                          className={inputClass}
                        >
                          {surveyQuestionTypes.map((type) => (
                            <option key={type} value={type}>
                              {surveyQuestionTypeMeta[type].label}
                            </option>
                          ))}
                        </select>
                      </Field>

                      <Field label="Prompt" error={questionError(index, 'prompt')}>
                        <input
                          value={question.prompt}
                          onChange={(event) =>
                            updateQuestion(question.key, { prompt: event.target.value })
                          }
                          placeholder="How satisfied are you with the deployment?"
                          disabled={retired}
                          className={inputClass}
                        />
                      </Field>
                    </div>

                    <div className="mt-3">
                      <Field label="Help line (optional)" error={questionError(index, 'help')}>
                        <input
                          value={question.help}
                          onChange={(event) =>
                            updateQuestion(question.key, { help: event.target.value })
                          }
                          placeholder="0 = not at all likely, 10 = extremely likely."
                          disabled={retired}
                          className={inputClass}
                        />
                      </Field>
                    </div>

                    <p className="mt-2 text-xs text-muted-foreground">
                      {surveyQuestionTypeMeta[question.type].hint}
                    </p>

                    {surveyQuestionTypeMeta[question.type].hasOptions && (
                      <div className="mt-3">
                        <p className="text-sm font-medium text-foreground">Options</p>
                        {questionError(index, 'options') && (
                          <p className="mt-1 text-xs text-destructive">
                            {questionError(index, 'options')?.join(' ')}
                          </p>
                        )}
                        <ul className="mt-2 flex flex-col gap-2">
                          {question.options.map((option, optionIndex) => (
                            <li key={optionIndex} className="flex items-center gap-2">
                              <input
                                value={option}
                                aria-label={`Option ${optionIndex + 1} of question ${index + 1}`}
                                onChange={(event) => {
                                  const next = [...question.options]
                                  next[optionIndex] = event.target.value
                                  updateQuestion(question.key, { options: next })
                                }}
                                placeholder={`Option ${optionIndex + 1}`}
                                disabled={retired}
                                className={inputClass}
                              />
                              <button
                                type="button"
                                onClick={() =>
                                  updateQuestion(question.key, {
                                    options: question.options.filter(
                                      (_, position) => position !== optionIndex,
                                    ),
                                  })
                                }
                                disabled={question.options.length <= 2 || retired}
                                aria-label={`Remove option ${optionIndex + 1} of question ${index + 1}`}
                                className="rounded-md p-1 text-destructive hover:bg-muted disabled:opacity-40"
                              >
                                <Trash2 className="h-4 w-4" aria-hidden="true" />
                              </button>
                            </li>
                          ))}
                        </ul>
                        <button
                          type="button"
                          onClick={() =>
                            updateQuestion(question.key, {
                              options: [...question.options, ''],
                            })
                          }
                          disabled={question.options.length >= 10 || retired}
                          className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-counts disabled:opacity-50"
                        >
                          <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                          Add option
                        </button>
                      </div>
                    )}

                    <label className="mt-3 flex items-center gap-2 text-sm font-medium text-foreground">
                      <input
                        type="checkbox"
                        checked={question.required}
                        onChange={(event) =>
                          updateQuestion(question.key, { required: event.target.checked })
                        }
                        disabled={retired}
                        className="h-4 w-4 rounded border-input"
                      />
                      Required — the client cannot submit without answering
                    </label>
                  </li>
                ))}
              </ul>

              <button
                type="button"
                onClick={() => setQuestions((current) => [...current, draft()])}
                disabled={questions.length >= 30 || retired}
                className="mt-4 inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-50"
              >
                <Plus className="h-4 w-4" aria-hidden="true" />
                Add question
              </button>
            </FormCard>
          </div>

          <div className="mt-6 flex items-center gap-3">
            <button
              type="submit"
              disabled={saving || loading || retired}
              className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
            >
              <Save className="h-4 w-4" aria-hidden="true" />
              {saving ? 'Saving…' : editing ? 'Save survey' : 'Create survey'}
            </button>
            <button
              type="button"
              onClick={() => navigate(-1)}
              className="rounded-md border border-border bg-card px-4 py-2 text-sm font-medium text-foreground hover:bg-muted"
            >
              Cancel
            </button>
          </div>
        </div>

        <aside>
          <FormCard
            title="Preview"
            description="How the questions read on the client’s page — the link itself arrives in slice S2."
          >
            {name.trim() && (
              <p className="text-base font-semibold text-foreground">{name.trim()}</p>
            )}
            {description.trim() && (
              <p className="mt-1 text-sm text-muted-foreground">{description.trim()}</p>
            )}

            {questions.length === 0 ? (
              <p className="mt-4 text-sm text-muted-foreground">Nothing to preview yet.</p>
            ) : (
              <ol className="mt-4 flex flex-col gap-5">
                {questions.map((question, index) => (
                  <li key={question.key}>
                    <p className="text-sm font-medium text-foreground">
                      {index + 1}. {question.prompt.trim() || 'Untitled question'}
                      {question.required && <span className="text-destructive"> *</span>}
                    </p>
                    {question.help.trim() && (
                      <p className="mt-0.5 text-xs text-muted-foreground">{question.help.trim()}</p>
                    )}
                    <QuestionPreview question={question} />
                  </li>
                ))}
              </ol>
            )}
          </FormCard>
        </aside>
      </form>
    </div>
  )
}

function QuestionPreview({ question }: { question: QuestionDraft }) {
  if (question.type === 'rating' || question.type === 'nps') {
    const values =
      question.type === 'rating'
        ? [1, 2, 3, 4, 5]
        : [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]

    return (
      <div className="mt-2 flex flex-wrap gap-1">
        {values.map((value) => (
          <span
            key={value}
            className="inline-flex h-8 min-w-8 items-center justify-center rounded-md border border-border px-2 text-xs text-muted-foreground"
          >
            {value}
          </span>
        ))}
      </div>
    )
  }

  if (question.type === 'text') {
    return (
      <div className="mt-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
        The client writes their answer here.
      </div>
    )
  }

  const options = question.options.filter((option) => option.trim() !== '')

  return (
    <ul className="mt-2 flex flex-col gap-1">
      {options.length === 0 && (
        <li className="text-xs text-muted-foreground">No options yet.</li>
      )}
      {options.map((option, index) => (
        <li key={index} className="flex items-center gap-2 text-sm text-foreground">
          <span
            className={`inline-block h-3.5 w-3.5 border border-input ${
              question.type === 'single_choice' ? 'rounded-full' : 'rounded-sm'
            }`}
            aria-hidden="true"
          />
          {option}
        </li>
      ))}
    </ul>
  )
}

function Field({
  label,
  error,
  children,
}: {
  label: string
  error?: string[]
  children: ReactNode
}) {
  return (
    <label className="block text-sm font-medium text-foreground">
      {label}
      {children}
      {error && <span className="mt-1 block text-xs text-destructive">{error.join(' ')}</span>}
    </label>
  )
}
