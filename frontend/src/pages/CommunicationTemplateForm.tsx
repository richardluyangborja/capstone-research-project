import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Save } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import AlertItem from '../components/common/AlertItem'
import {
  createCommunicationTemplate,
  getCommunicationTemplate,
  listCommunicationMergeFields,
  updateCommunicationTemplate,
} from '../lib/communicationTemplates'
import { communicationChannelMeta } from '../lib/communicationMeta'
import { parseValidationError } from '../lib/leads'
import type {
  CommunicationChannel,
  CommunicationMergeField,
  CommunicationTemplateInput,
} from '../types'

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

/** Which field a merge-field chip drops its token into. */
type FieldName = 'subject' | 'body'

// Module 03 §UI (slice S5) — authoring the house's wording. Manager+ only (the API refuses a
// rep); a saved message never points back here, so editing this later is harmless.
export default function CommunicationTemplateForm() {
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const editing = Boolean(id)

  const [name, setName] = useState('')
  const [channel, setChannel] = useState<CommunicationChannel>('email')
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [isActive, setIsActive] = useState(true)

  const [fields, setFields] = useState<CommunicationMergeField[]>([])
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(editing)
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  // Which field the caret was last in, so a chip inserts where the author is writing.
  const lastField = useRef<FieldName>('body')
  const subjectRef = useRef<HTMLInputElement>(null)
  const bodyRef = useRef<HTMLTextAreaElement>(null)

  useEffect(() => {
    void (async () => {
      try {
        setFields(await listCommunicationMergeFields())
      } catch {
        // The chips are a convenience; the wording works without them.
      }
    })()
  }, [])

  useEffect(() => {
    if (!id) return
    void (async () => {
      try {
        const template = await getCommunicationTemplate(id)
        setName(template.name)
        setChannel(template.channel)
        setSubject(template.subject)
        setBody(template.body)
        setIsActive(template.is_active)
      } catch {
        setMessage(
          'Could not load that template. It may have been retired — look it up in the book and restore it first.',
        )
      } finally {
        setLoading(false)
      }
    })()
  }, [id])

  const insertField = useCallback(
    (token: string) => {
      const placeholder = `{{${token}}}`
      const target = lastField.current === 'subject' ? subjectRef.current : bodyRef.current
      const value = lastField.current === 'subject' ? subject : body
      const setValue = lastField.current === 'subject' ? setSubject : setBody

      // No caret to speak of (the field was never touched, or the browser keeps no selection):
      // the token simply goes on the end rather than nowhere.
      const start = target?.selectionStart ?? value.length
      const end = target?.selectionEnd ?? start
      setValue(`${value.slice(0, start)}${placeholder}${value.slice(end)}`)

      if (target && typeof target.setSelectionRange === 'function') {
        target.focus()
        target.setSelectionRange(start + placeholder.length, start + placeholder.length)
      }
    },
    [subject, body],
  )

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setMessage('')
    setErrors({})

    const input: CommunicationTemplateInput = {
      name: name.trim(),
      channel,
      subject: subject.trim(),
      body,
      is_active: isActive,
    }

    setSaving(true)
    try {
      if (id) {
        await updateCommunicationTemplate(id, input)
      } else {
        await createCommunicationTemplate(input)
      }
      navigate('/communications/templates')
    } catch (error) {
      const validation = parseValidationError(error)
      if (validation) {
        setErrors(validation.errors)
        setMessage(validation.message)
      } else {
        setMessage('Could not save the template.')
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <PageHeader
        title={editing ? 'Edit template' : 'New template'}
        description="The wording a message can start from — written once, rendered with the record's own details."
        actions={
          <Link
            to="/communications/templates"
            className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
            Template book
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

      <form onSubmit={onSubmit} className="max-w-3xl">
        <FormCard title="The template">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Field label="Name" error={errors.name}>
              <input
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="Rate sheet follow-up"
                disabled={loading}
                className={inputClass}
              />
            </Field>

            <Field label="Channel" error={errors.channel}>
              <select
                value={channel}
                onChange={(event) => setChannel(event.target.value as CommunicationChannel)}
                disabled={loading}
                className={inputClass}
              >
                {(Object.keys(communicationChannelMeta) as CommunicationChannel[]).map((value) => (
                  <option key={value} value={value}>
                    {communicationChannelMeta[value].label}
                  </option>
                ))}
              </select>
            </Field>
          </div>

          <div className="mt-4">
            <Field label="Title line" error={errors.subject}>
              <input
                ref={subjectRef}
                value={subject}
                onFocus={() => {
                  lastField.current = 'subject'
                }}
                onChange={(event) => setSubject(event.target.value)}
                placeholder="Our updated rate sheet for {{company.name}}"
                disabled={loading}
                className={inputClass}
              />
            </Field>
          </div>

          <div className="mt-4">
            <Field label="Body" error={errors.body}>
              <textarea
                ref={bodyRef}
                value={body}
                onFocus={() => {
                  lastField.current = 'body'
                }}
                onChange={(event) => setBody(event.target.value)}
                rows={10}
                disabled={loading}
                className={inputClass}
              />
            </Field>
          </div>

          <label className="mt-4 flex items-center gap-2 text-sm font-medium text-foreground">
            <input
              type="checkbox"
              checked={isActive}
              onChange={(event) => setIsActive(event.target.checked)}
              disabled={loading}
              className="h-4 w-4 rounded border-input"
            />
            Offered in the composer
          </label>
          <p className="mt-1 text-xs text-muted-foreground">
            An inactive template stays here but is not offered when writing a message.
          </p>
        </FormCard>

        <div className="mt-6">
          <FormCard
            title="Merge fields"
            description="Click one to drop it into the field you were writing in. A field the record cannot fill stays as it is and is reported when it renders."
          >
            {fields.length === 0 ? (
              <p className="text-sm text-muted-foreground">No merge fields available.</p>
            ) : (
              <div className="flex flex-wrap gap-2">
                {fields.map((field) => (
                  <button
                    key={field.token}
                    type="button"
                    onClick={() => insertField(field.token)}
                    title={field.label}
                    className="rounded-md border border-border bg-card px-2 py-1 font-mono text-xs text-foreground hover:bg-muted"
                  >
                    {`{{${field.token}}}`}
                  </button>
                ))}
              </div>
            )}
          </FormCard>
        </div>

        <div className="mt-6 flex items-center gap-3">
          <button
            type="submit"
            disabled={saving || loading}
            className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
          >
            <Save className="h-4 w-4" aria-hidden="true" />
            {saving ? 'Saving…' : editing ? 'Save template' : 'Create template'}
          </button>
          <button
            type="button"
            onClick={() => navigate(-1)}
            className="rounded-md border border-border bg-card px-4 py-2 text-sm font-medium text-foreground hover:bg-muted"
          >
            Cancel
          </button>
        </div>
      </form>
    </div>
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
      {error && <span className="mt-1 block text-xs text-destructive">{error[0]}</span>}
    </label>
  )
}
