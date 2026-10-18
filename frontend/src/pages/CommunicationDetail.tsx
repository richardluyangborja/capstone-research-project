import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  ArrowDownLeft,
  ArrowLeft,
  ArrowUpRight,
  Download,
  FileUp,
  Paperclip,
  Pencil,
  RotateCcw,
  Send,
  Trash2,
} from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import AlertItem from '../components/common/AlertItem'
import StatusBadge from '../components/common/StatusBadge'
import {
  deleteCommunication,
  getCommunication,
  restoreCommunication,
  retryCommunication,
  sendCommunication,
  updateCommunication,
} from '../lib/communications'
import { deleteAttachment, downloadAttachment, uploadAttachment } from '../lib/attachments'
import {
  communicationChannelMeta,
  communicationDirectionMeta,
  communicationOutcomes,
  communicationOutcomeMeta,
  communicationRecordPath,
  communicationStatusMeta,
  communicationSubjectLabels,
} from '../lib/communicationMeta'
import { formatDateTime, formatFileSize, toDateTimeLocal } from '../lib/format'
import { parseValidationError } from '../lib/leads'
import type { Attachment, Communication, CommunicationOutcome } from '../types'

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

// Module 03 §UI — one history entry: what was said, to whom, when, and (for anything the
// author logged rather than sent) the ability to correct it.
export default function CommunicationDetail() {
  const { id = '' } = useParams()
  const navigate = useNavigate()

  const [communication, setCommunication] = useState<Communication | null>(null)
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [variant, setVariant] = useState<'danger' | 'success'>('danger')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [saving, setSaving] = useState(false)

  const [editing, setEditing] = useState(false)
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [occurredAt, setOccurredAt] = useState('')
  const [duration, setDuration] = useState('')
  const [outcome, setOutcome] = useState<CommunicationOutcome | ''>('')

  const [attachmentFile, setAttachmentFile] = useState<File | null>(null)
  const [attachmentError, setAttachmentError] = useState('')
  const [attachmentBusy, setAttachmentBusy] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const record = await getCommunication(id)
      setCommunication(record)
      setTitle(record.subject)
      setBody(record.body ?? '')
      setOccurredAt(toDateTimeLocal(record.occurred_at))
      setDuration(record.duration_minutes === null ? '' : String(record.duration_minutes))
      setOutcome(record.outcome ?? '')
    } catch {
      setVariant('danger')
      setMessage('Could not load this communication.')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    void load()
  }, [load])

  const handleFailure = (error: unknown, fallback: string) => {
    const validation = parseValidationError(error)
    setVariant('danger')
    if (validation) {
      setErrors(validation.errors)
      setMessage(validation.message)
    } else {
      setMessage(fallback)
    }
  }

  const onSave = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    setErrors({})
    try {
      const updated = await updateCommunication(id, {
        subject: title.trim(),
        body: body.trim() === '' ? null : body,
        occurred_at: occurredAt ? new Date(occurredAt).toISOString() : undefined,
        duration_minutes: duration.trim() === '' ? null : Number(duration),
        outcome: outcome === '' ? null : outcome,
      })
      setCommunication(updated)
      setEditing(false)
      setVariant('success')
      setMessage('Communication updated.')
    } catch (error) {
      handleFailure(error, 'Could not update the communication.')
    } finally {
      setSaving(false)
    }
  }

  /** Sending (or retrying) is the one action that leaves the app (spec 11 §S2). */
  const onGateway = async () => {
    if (!communication) return
    setSaving(true)
    setMessage('')
    setErrors({})
    try {
      const updated =
        communication.status === 'draft'
          ? await sendCommunication(id)
          : await retryCommunication(id)
      setCommunication(updated)
      setVariant(updated.status === 'sent' ? 'success' : 'danger')
      setMessage(
        updated.status === 'sent'
          ? `Sent through the mocked mailbox — ${updated.external_id}.`
          : 'The gateway rejected it again; the reason is below.',
      )
    } catch (error) {
      handleFailure(error, 'The gateway could not be reached.')
    } finally {
      setSaving(false)
    }
  }

  /** Attaching and removing files are work on the message, so they follow its rights (S3). */
  const attachmentFailure = (error: unknown) => {
    const validation = parseValidationError(error)
    setAttachmentError(
      validation?.errors.file?.[0] ??
        validation?.errors.status?.[0] ??
        validation?.message ??
        'Could not change the files on this message.',
    )
  }

  const onAttach = async () => {
    if (!communication || !attachmentFile) return
    setAttachmentBusy(true)
    setAttachmentError('')
    try {
      const created = await uploadAttachment(communication.id, attachmentFile)
      setCommunication({
        ...communication,
        attachments: [...(communication.attachments ?? []), created],
      })
      setAttachmentFile(null)
      // The input is uncontrolled: clear it so the same file can be picked again.
      if (fileInput.current) fileInput.current.value = ''
    } catch (error) {
      attachmentFailure(error)
    } finally {
      setAttachmentBusy(false)
    }
  }

  const onRemoveAttachment = async (attachment: Attachment) => {
    if (!communication) return
    setAttachmentBusy(true)
    setAttachmentError('')
    try {
      await deleteAttachment(attachment.id)
      setCommunication({
        ...communication,
        attachments: (communication.attachments ?? []).filter((one) => one.id !== attachment.id),
      })
    } catch (error) {
      attachmentFailure(error)
    } finally {
      setAttachmentBusy(false)
    }
  }

  const onDownloadAttachment = async (attachment: Attachment) => {
    setAttachmentError('')
    try {
      await downloadAttachment(attachment)
    } catch {
      setAttachmentError('Could not download that file.')
    }
  }

  const onDelete = async () => {
    setSaving(true)
    setMessage('')
    try {
      await deleteCommunication(id)
      navigate('/communications')
    } catch (error) {
      handleFailure(error, 'Could not retire the communication.')
    } finally {
      setSaving(false)
    }
  }

  const onRestore = async () => {
    setSaving(true)
    setMessage('')
    try {
      setCommunication(await restoreCommunication(id))
      setVariant('success')
      setMessage('Communication restored.')
    } catch (error) {
      handleFailure(error, 'Could not restore the communication.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return <p className="text-sm text-muted-foreground">Loading communication…</p>
  }

  if (!communication) {
    return (
      <div>
        <AlertItem variant="danger">{message || 'Communication not found.'}</AlertItem>
        <Link to="/communications" className="mt-4 inline-block text-sm font-medium text-counts">
          Back to the history book
        </Link>
      </div>
    )
  }

  const channel = communicationChannelMeta[communication.channel]
  const direction = communicationDirectionMeta[communication.direction]
  const status = communicationStatusMeta[communication.status]
  const DirectionIcon = communication.direction === 'inbound' ? ArrowDownLeft : ArrowUpRight
  const recordHref = communicationRecordPath(communication.record)
  const retired = communication.deleted_at !== null
  // Only a gateway-backed email carries the mocked mailbox's id; a hand-logged "sent" email
  // never went near it, so it must not claim to have.
  const gatewayEmail = communication.channel === 'email' && communication.external_id !== null
  // Files are part of the record of what was said, so a message that has left or arrived keeps
  // the ones it carried — the API is the authority, not the screen (slice S3).
  const attachments = communication.attachments ?? []
  const canAttach = communication.can_attach && !retired

  return (
    <div>
      <PageHeader
        title={communication.subject}
        description={`${communication.reference} · logged by ${communication.owner?.name ?? 'an unknown user'}`}
        actions={
          <>
            <Link
              to="/communications"
              className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
            >
              <ArrowLeft className="h-4 w-4" aria-hidden="true" />
              History book
            </Link>
            {communication.can_update && communication.is_editable && !retired && (
              <button
                type="button"
                onClick={() => setEditing((previous) => !previous)}
                className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
              >
                <Pencil className="h-4 w-4" aria-hidden="true" />
                {editing ? 'Close editor' : 'Edit'}
              </button>
            )}
            {!retired && communication.can_send && (
              <button
                type="button"
                onClick={() => void onGateway()}
                disabled={saving}
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
              >
                <Send className="h-4 w-4" aria-hidden="true" />
                {communication.status === 'failed' ? 'Retry send' : 'Send now'}
              </button>
            )}
            {retired && communication.can_restore && (
              <button
                type="button"
                onClick={() => void onRestore()}
                disabled={saving}
                className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
              >
                <RotateCcw className="h-4 w-4" aria-hidden="true" />
                Restore
              </button>
            )}
            {!retired && communication.can_delete && (
              <button
                type="button"
                onClick={() => void onDelete()}
                disabled={saving}
                className="inline-flex items-center gap-2 rounded-md border border-destructive/40 bg-card px-3 py-2 text-sm font-medium text-destructive hover:bg-destructive/10 disabled:opacity-60"
              >
                <Trash2 className="h-4 w-4" aria-hidden="true" />
                Retire
              </button>
            )}
          </>
        }
      />

      {message && (
        <div className="mb-4">
          <AlertItem variant={variant}>
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
          <AlertItem variant="warning">
            This entry was retired on {formatDateTime(communication.deleted_at)}.
          </AlertItem>
        </div>
      )}

      {communication.status === 'failed' && (
        <div className="mb-4">
          <AlertItem variant="danger" title="Delivery failed">
            {communication.failed_reason ?? 'The gateway rejected this message.'}
          </AlertItem>
        </div>
      )}

      {!retired && communication.status === 'draft' && (
        <div className="mb-4">
          <AlertItem variant="info" title="Draft — not sent yet">
            Written but not delivered. Send it when the wording is right; until then it stays off
            the record's timeline.
          </AlertItem>
        </div>
      )}

      {!retired && gatewayEmail && communication.status !== 'failed' && (
        <div className="mb-4">
          <AlertItem variant="info" title="Simulated mailbox">
            {communication.direction === 'inbound'
              ? 'Pulled from the mocked mailbox'
              : 'Sent through the mocked email gateway'}{' '}
            — {communication.external_id}. No external mail provider is involved.
          </AlertItem>
        </div>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="flex flex-col gap-6 lg:col-span-2">
          {editing ? (
            <form onSubmit={onSave}>
              <FormCard
                title="Correct this entry"
                description="Only what was logged can be corrected — the channel, direction and status are fixed."
                footer={
                  <>
                    <button
                      type="submit"
                      disabled={saving}
                      className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
                    >
                      {saving ? 'Saving…' : 'Save changes'}
                    </button>
                    <button
                      type="button"
                      onClick={() => setEditing(false)}
                      className="rounded-md border border-border bg-card px-4 py-2 text-sm font-medium text-foreground hover:bg-muted"
                    >
                      Cancel
                    </button>
                  </>
                }
              >
                <label className="block text-sm font-medium text-foreground">
                  Subject
                  <input
                    value={title}
                    onChange={(event) => setTitle(event.target.value)}
                    className={inputClass}
                  />
                  {errors.subject && (
                    <span className="mt-1 block text-xs text-destructive">{errors.subject[0]}</span>
                  )}
                </label>

                <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                  <label className="block text-sm font-medium text-foreground">
                    When
                    <input
                      type="datetime-local"
                      value={occurredAt}
                      onChange={(event) => setOccurredAt(event.target.value)}
                      className={inputClass}
                    />
                    {errors.occurred_at && (
                      <span className="mt-1 block text-xs text-destructive">
                        {errors.occurred_at[0]}
                      </span>
                    )}
                  </label>

                  <label className="block text-sm font-medium text-foreground">
                    Duration (minutes)
                    <input
                      type="number"
                      min={0}
                      value={duration}
                      onChange={(event) => setDuration(event.target.value)}
                      className={inputClass}
                    />
                  </label>

                  <label className="block text-sm font-medium text-foreground">
                    Outcome
                    <select
                      value={outcome}
                      onChange={(event) =>
                        setOutcome(event.target.value as CommunicationOutcome | '')
                      }
                      className={inputClass}
                    >
                      <option value="">Not recorded</option>
                      {communicationOutcomes.map((value) => (
                        <option key={value} value={value}>
                          {communicationOutcomeMeta[value].label}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>

                <label className="mt-4 block text-sm font-medium text-foreground">
                  Body
                  <textarea
                    value={body}
                    onChange={(event) => setBody(event.target.value)}
                    rows={8}
                    className={inputClass}
                  />
                </label>
              </FormCard>
            </form>
          ) : (
            <FormCard title="What was said">
              {communication.body ? (
                <p className="whitespace-pre-wrap text-sm text-foreground">{communication.body}</p>
              ) : (
                <p className="text-sm text-muted-foreground">No body was recorded.</p>
              )}
            </FormCard>
          )}

          <FormCard
            title="Attachments"
            description="PDF, PNG or JPEG. Files are stored privately and served through the API."
          >
            {attachments.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                {canAttach ? 'Nothing attached yet.' : 'This message carries no files.'}
              </p>
            ) : (
              <ul className="flex flex-col gap-3">
                {attachments.map((attachment) => (
                  <li key={attachment.id} className="flex items-center justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-2">
                      <Paperclip className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
                      <div className="min-w-0">
                        <button
                          type="button"
                          onClick={() => void onDownloadAttachment(attachment)}
                          className="block max-w-full truncate text-sm font-medium text-counts"
                        >
                          {attachment.name}
                        </button>
                        <p className="text-xs text-muted-foreground">
                          {formatFileSize(attachment.size)}
                          {attachment.uploader ? ` · ${attachment.uploader.name}` : ''}
                        </p>
                      </div>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                      <button
                        type="button"
                        onClick={() => void onDownloadAttachment(attachment)}
                        aria-label={`Download ${attachment.name}`}
                        className="inline-flex items-center gap-1 rounded-md border border-border bg-card px-2 py-1 text-xs font-medium text-foreground hover:bg-muted"
                      >
                        <Download className="h-3.5 w-3.5" aria-hidden />
                        Download
                      </button>
                      {canAttach && attachment.can_delete && (
                        <button
                          type="button"
                          onClick={() => void onRemoveAttachment(attachment)}
                          disabled={attachmentBusy}
                          aria-label={`Remove ${attachment.name}`}
                          className="inline-flex items-center rounded-md border border-destructive/40 bg-card px-2 py-1 text-xs font-medium text-destructive hover:bg-destructive/10 disabled:opacity-60"
                        >
                          <Trash2 className="h-3.5 w-3.5" aria-hidden />
                        </button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}

            {attachmentError && <p className="mt-3 text-xs text-destructive">{attachmentError}</p>}

            {canAttach ? (
              <div className="mt-4 flex flex-wrap items-center gap-3 border-t border-border pt-4">
                <input
                  ref={fileInput}
                  type="file"
                  accept="application/pdf,image/png,image/jpeg"
                  onChange={(event) => setAttachmentFile(event.target.files?.[0] ?? null)}
                  className="text-sm text-foreground"
                />
                <button
                  type="button"
                  onClick={() => void onAttach()}
                  disabled={!attachmentFile || attachmentBusy}
                  className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
                >
                  <FileUp className="h-4 w-4" aria-hidden />
                  {attachmentBusy ? 'Attaching…' : 'Attach file'}
                </button>
              </div>
            ) : (
              !retired && (
                <p className="mt-4 border-t border-border pt-4 text-xs text-muted-foreground">
                  A {communication.status} message keeps the files it carried.
                </p>
              )
            )}
          </FormCard>

          <FormCard title="Participants">
            <dl className="grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
              <Detail
                label="From"
                value={communication.from_name}
                sub={communication.from_address}
              />
              <Detail label="To" value={communication.to_name} sub={communication.to_address} />
              <Detail
                label="Contact"
                value={communication.contact?.name ?? null}
                sub={communication.contact?.email ?? null}
              />
              <Detail
                label="Duration"
                value={
                  communication.duration_minutes === null
                    ? null
                    : `${communication.duration_minutes} minutes`
                }
              />
            </dl>
          </FormCard>
        </div>

        <div className="flex flex-col gap-6">
          <FormCard title="Summary">
            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge tone={channel.tone}>{channel.label}</StatusBadge>
              <StatusBadge tone={direction.tone}>
                <span className="inline-flex items-center gap-1">
                  <DirectionIcon className="h-3 w-3" aria-hidden />
                  {direction.label}
                </span>
              </StatusBadge>
              <StatusBadge tone={status.tone}>{status.label}</StatusBadge>
              {communication.outcome && (
                <StatusBadge tone={communicationOutcomeMeta[communication.outcome].tone}>
                  {communicationOutcomeMeta[communication.outcome].label}
                </StatusBadge>
              )}
            </div>

            <dl className="mt-4 flex flex-col gap-3 text-sm">
              <div>
                <dt className="text-xs font-medium text-muted-foreground">
                  {communication.record
                    ? communicationSubjectLabels[communication.record.type]
                    : 'Record'}
                </dt>
                <dd className="text-foreground">
                  {communication.record ? (
                    recordHref ? (
                      <Link to={recordHref} className="font-medium text-counts">
                        {communication.record.label ?? communication.record.id}
                      </Link>
                    ) : (
                      (communication.record.label ?? communication.record.id)
                    )
                  ) : (
                    '—'
                  )}
                </dd>
              </div>
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Occurred</dt>
                <dd className="text-foreground">{formatDateTime(communication.occurred_at)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Logged by</dt>
                <dd className="text-foreground">{communication.owner?.name ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Reference</dt>
                <dd className="font-mono text-xs text-foreground">{communication.reference}</dd>
              </div>
              {communication.external_id && (
                <div>
                  <dt className="text-xs font-medium text-muted-foreground">Mailbox id</dt>
                  <dd className="font-mono text-xs text-foreground">{communication.external_id}</dd>
                </div>
              )}
            </dl>

            {communication.record && !recordHref && (
              <p className="mt-4 text-xs text-muted-foreground">
                Open it from the{' '}
                {communicationSubjectLabels[communication.record.type].toLowerCase()}
                's own page for the full picture.
              </p>
            )}
          </FormCard>
        </div>
      </div>
    </div>
  )
}

function Detail({
  label,
  value,
  sub,
}: {
  label: string
  value: string | null
  sub?: string | null
}) {
  return (
    <div>
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="text-foreground">
        {value ?? '—'}
        {sub && <span className="block text-xs text-muted-foreground">{sub}</span>}
      </dd>
    </div>
  )
}
