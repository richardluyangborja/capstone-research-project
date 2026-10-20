import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Copy, Loader2, Plus, RotateCw, Send, XCircle } from 'lucide-react'
import StatusBadge from './StatusBadge'
import EmptyState from './EmptyState'
import AlertItem from './AlertItem'
import { listCompanyContacts } from '../../lib/contacts'
import { listCompanyContracts } from '../../lib/contracts'
import { parseValidationError } from '../../lib/leads'
import {
  cancelSurveyInvitation,
  listCompanySurveyInvitations,
  listSurveys,
  resendSurveyInvitation,
  sendSurveyInvitation,
} from '../../lib/surveys'
import { formatCsat, formatNps, surveyInvitationStateMeta } from '../../lib/surveyMeta'
import { formatDateTime } from '../../lib/format'
import type { Contact, Contract, Survey, SurveyInvitation } from '../../types'

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

interface SendForm {
  surveyId: string
  contactId: string
  contractId: string
  email: string
  message: string
}

const emptyForm: SendForm = {
  surveyId: '',
  contactId: '',
  contractId: '',
  email: '',
  message: '',
}

/**
 * The account's Satisfaction tab (module 05 slices S1–S2, spec 13 §UI).
 *
 * Slice S1 left an empty state pointing at the library; slice S2 makes it the place a survey is
 * actually sent from — the right place for the permission, because sending needs the account's
 * `update` right. Each row is one send: the survey, who it went to, its derived state and, once
 * answered, the score that came back. The client's answers themselves are the response book's
 * job (slice S3).
 */
export default function CompanySatisfactionPanel({
  companyId,
  canSend,
}: {
  companyId: string
  canSend: boolean
}) {
  const [invitations, setInvitations] = useState<SurveyInvitation[]>([])
  const [surveys, setSurveys] = useState<Survey[]>([])
  const [contacts, setContacts] = useState<Contact[]>([])
  const [contracts, setContracts] = useState<Contract[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<SendForm>(emptyForm)
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({})
  const [formMessage, setFormMessage] = useState('')
  const [sending, setSending] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await listCompanySurveyInvitations(companyId)
      setInvitations(response.data)
    } catch {
      setError('Could not load this account’s survey history.')
    } finally {
      setLoading(false)
    }
  }, [companyId])

  useEffect(() => {
    void load()
  }, [load])

  const openDialog = async () => {
    setOpen(true)
    setForm(emptyForm)
    setFormErrors({})
    setFormMessage('')
    setError('')

    try {
      const [library, people, deals] = await Promise.all([
        listSurveys({ state: 'active' }),
        listCompanyContacts(companyId),
        listCompanyContracts(companyId),
      ])
      setSurveys(library.data)
      setContacts(people)
      setContracts(deals.data)
    } catch {
      setError('Could not load the library. Close the dialog and try again.')
    }
  }

  const onSend = async (event: FormEvent) => {
    event.preventDefault()
    setFormErrors({})
    setFormMessage('')

    const contact = contacts.find((entry) => entry.id === form.contactId)
    const email = form.email.trim() || contact?.email || ''

    if (!form.surveyId) {
      setFormErrors({ survey_id: ['Choose a survey to send.'] })
      return
    }

    if (!contact && !email) {
      setFormErrors({ recipient_email: ['Pick a contact, or type the address to send to.'] })
      return
    }

    setSending(true)
    try {
      const invitation = await sendSurveyInvitation(form.surveyId, {
        company_id: companyId,
        contact_id: form.contactId || null,
        contract_id: form.contractId || null,
        recipient_email: form.email.trim() || null,
        message: form.message.trim() || null,
      })

      setOpen(false)
      setNotice(
        invitation.state === 'failed'
          ? `The simulated mailbox rejected the message: ${invitation.failed_reason ?? 'no reason given'}. You can resend it.`
          : `Sent to ${invitation.recipient_email} — the link closes ${formatDay(invitation.expires_at)}.`,
      )
      await load()
    } catch (thrown) {
      const validation = parseValidationError(thrown)
      if (validation) {
        setFormErrors(validation.errors)
        setFormMessage(validation.message)
      } else {
        setFormMessage('Could not send that survey.')
      }
    } finally {
      setSending(false)
    }
  }

  const onCopy = async (invitation: SurveyInvitation) => {
    if (!invitation.public_url) return

    try {
      await navigator.clipboard.writeText(invitation.public_url)
      setNotice(`Link copied — it opens the survey sent to ${invitation.recipient_email}.`)
    } catch {
      setNotice(`Copy this link: ${invitation.public_url}`)
    }
  }

  const onResend = async (invitation: SurveyInvitation) => {
    setSaving(invitation.id)
    setError('')
    setNotice('')
    try {
      const updated = await resendSurveyInvitation(invitation.id)
      setNotice(
        updated.state === 'sent'
          ? `Sent again to ${updated.recipient_email}, with a fresh window.`
          : `The mailbox rejected it again: ${updated.failed_reason ?? 'no reason given'}.`,
      )
      await load()
    } catch {
      setError('Could not resend that invitation.')
    } finally {
      setSaving('')
    }
  }

  const onCancel = async (invitation: SurveyInvitation) => {
    setSaving(invitation.id)
    setError('')
    setNotice('')
    try {
      await cancelSurveyInvitation(invitation.id)
      setNotice('Withdrawn — the link no longer works.')
      await load()
    } catch {
      setError('Could not withdraw that invitation.')
    } finally {
      setSaving('')
    }
  }

  const selectedContact = contacts.find((entry) => entry.id === form.contactId)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-foreground">Satisfaction</h2>
          <p className="text-sm text-muted-foreground">
            What this account has been asked, and what it answered.
          </p>
        </div>
        {canSend && (
          <button
            type="button"
            onClick={() => void openDialog()}
            className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
          >
            <Send className="h-4 w-4" aria-hidden="true" />
            Send survey
          </button>
        )}
      </div>

      {notice && <AlertItem variant="success">{notice}</AlertItem>}
      {error && <AlertItem variant="danger">{error}</AlertItem>}

      {loading ? (
        <p className="text-sm text-muted-foreground">Loading survey history…</p>
      ) : invitations.length === 0 ? (
        <EmptyState
          title="No survey sent yet"
          description={
            canSend
              ? 'Send one of the surveys in the library to hear how the engagement is going.'
              : 'A manager or the account owner can send a survey from here.'
          }
        />
      ) : (
        <ul className="flex flex-col gap-2">
          {invitations.map((invitation) => (
            <li
              key={invitation.id}
              className="rounded-lg border border-border bg-card px-4 py-3"
            >
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="font-medium text-foreground">
                    {invitation.survey?.name ?? 'Survey'}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {invitation.reference} · {invitation.recipient_name ?? invitation.recipient_email}
                    {invitation.recipient_name ? ` · ${invitation.recipient_email}` : ''}
                  </p>
                </div>
                <StatusBadge tone={surveyInvitationStateMeta[invitation.state].tone}>
                  {invitation.state_label}
                </StatusBadge>
              </div>

              <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                {invitation.sent_at && <span>Sent {formatDateTime(invitation.sent_at)}</span>}
                {invitation.submitted_at ? (
                  <span>Answered {formatDateTime(invitation.submitted_at)}</span>
                ) : (
                  invitation.expires_at && <span>Closes {formatDateTime(invitation.expires_at)}</span>
                )}
                {invitation.response && (
                  <span className="text-foreground">
                    CSAT {formatCsat(invitation.response.csat)} · NPS{' '}
                    {formatNps(invitation.response.nps)}
                    {invitation.response.needs_attention ? ' · needs attention' : ''}
                  </span>
                )}
                {invitation.failed_reason && (
                  <span className="text-destructive">{invitation.failed_reason}</span>
                )}
              </div>

              <div className="mt-2 flex flex-wrap items-center gap-3">
                {invitation.public_url && (
                  <button
                    type="button"
                    onClick={() => void onCopy(invitation)}
                    className="inline-flex items-center gap-1 text-xs font-medium text-counts"
                  >
                    <Copy className="h-3.5 w-3.5" aria-hidden="true" />
                    Copy link
                  </button>
                )}
                {invitation.can_resend && (
                  <button
                    type="button"
                    onClick={() => void onResend(invitation)}
                    disabled={saving === invitation.id}
                    className="inline-flex items-center gap-1 text-xs font-medium text-foreground disabled:opacity-60"
                  >
                    <RotateCw className="h-3.5 w-3.5" aria-hidden="true" />
                    Resend
                  </button>
                )}
                {invitation.can_cancel && (
                  <button
                    type="button"
                    onClick={() => void onCancel(invitation)}
                    disabled={saving === invitation.id}
                    className="inline-flex items-center gap-1 text-xs font-medium text-destructive disabled:opacity-60"
                  >
                    <XCircle className="h-3.5 w-3.5" aria-hidden="true" />
                    Withdraw
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {open && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label="Send a survey"
          className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4"
        >
          <form
            onSubmit={(event) => void onSend(event)}
            className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-lg border border-border bg-card p-5"
          >
            <h2 className="flex items-center gap-2 text-sm font-semibold text-foreground">
              <Plus className="h-4 w-4" aria-hidden="true" />
              Send a survey
            </h2>
            <p className="mt-1 text-sm text-muted-foreground">
              The client answers through a private link; the email is logged on the account.
            </p>

            {formMessage && (
              <div className="mt-3">
                <AlertItem variant="danger">{formMessage}</AlertItem>
              </div>
            )}

            <label className="mt-4 block text-sm font-medium text-foreground">
              Survey
              <select
                aria-label="Survey"
                value={form.surveyId}
                onChange={(event) => setForm({ ...form, surveyId: event.target.value })}
                className={inputClass}
              >
                <option value="">Choose an active survey…</option>
                {surveys.map((survey) => (
                  <option key={survey.id} value={survey.id}>
                    {survey.name}
                  </option>
                ))}
              </select>
              {formErrors.survey_id && (
                <span className="mt-1 block text-xs text-destructive">
                  {formErrors.survey_id.join(' ')}
                </span>
              )}
              {surveys.length === 0 && (
                <span className="mt-1 block text-xs text-muted-foreground">
                  The library has no active survey — activate one first.
                </span>
              )}
            </label>

            <label className="mt-3 block text-sm font-medium text-foreground">
              Contact
              <select
                aria-label="Contact"
                value={form.contactId}
                onChange={(event) => setForm({ ...form, contactId: event.target.value })}
                className={inputClass}
              >
                <option value="">Send to an address instead</option>
                {contacts.map((contact) => (
                  <option key={contact.id} value={contact.id}>
                    {contact.name}
                    {contact.position ? ` — ${contact.position}` : ''}
                    {contact.email ? '' : ' (no email)'}
                  </option>
                ))}
              </select>
            </label>

            <label className="mt-3 block text-sm font-medium text-foreground">
              {selectedContact ? 'Address (overrides the contact’s)' : 'Address'}
              <input
                type="email"
                aria-label="Address"
                value={form.email}
                onChange={(event) => setForm({ ...form, email: event.target.value })}
                placeholder={selectedContact?.email ?? 'name@client.ph'}
                className={inputClass}
              />
              {formErrors.recipient_email && (
                <span className="mt-1 block text-xs text-destructive">
                  {formErrors.recipient_email.join(' ')}
                </span>
              )}
            </label>

            <label className="mt-3 block text-sm font-medium text-foreground">
              Contract (optional)
              <select
                aria-label="Contract"
                value={form.contractId}
                onChange={(event) => setForm({ ...form, contractId: event.target.value })}
                className={inputClass}
              >
                <option value="">Not about a specific contract</option>
                {contracts.map((contract) => (
                  <option key={contract.id} value={contract.id}>
                    {contract.reference} — {contract.title}
                  </option>
                ))}
              </select>
            </label>

            <label className="mt-3 block text-sm font-medium text-foreground">
              Note to the client (optional)
              <textarea
                rows={3}
                aria-label="Note to the client"
                value={form.message}
                onChange={(event) => setForm({ ...form, message: event.target.value })}
                placeholder="A line about why you are asking, in your own words."
                className={inputClass}
              />
            </label>

            {formErrors.contact_id && (
              <p className="mt-2 text-xs text-destructive">{formErrors.contact_id.join(' ')}</p>
            )}
            {formErrors.contract_id && (
              <p className="mt-2 text-xs text-destructive">{formErrors.contract_id.join(' ')}</p>
            )}

            <div className="mt-5 flex justify-end gap-2">
              <button
                type="button"
                onClick={() => setOpen(false)}
                className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={sending}
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
              >
                {sending && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
                {sending ? 'Sending…' : 'Send survey'}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  )
}

function formatDay(value: string | null): string {
  if (!value) return 'in two weeks'

  return new Date(value).toLocaleDateString('en-PH', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}
