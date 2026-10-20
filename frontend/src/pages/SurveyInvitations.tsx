import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Copy, RotateCw, Search, XCircle } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import {
  cancelSurveyInvitation,
  listSurveyInvitations,
  listSurveys,
  resendSurveyInvitation,
  type SurveyInvitationFilters,
} from '../lib/surveys'
import {
  surveyInvitationStateMeta,
  surveyInvitationStates,
} from '../lib/surveyMeta'
import { formatDate, formatDateTime } from '../lib/format'
import type { Survey, SurveyInvitation, SurveyInvitationStateName } from '../types'

// Module 05 §UI (slice S2) — the invitation book. Every send of a survey, whichever account it
// went to, with the states that matter to a manager: who is still sitting on a link, who answered,
// what expired and what the mocked mailbox rejected.
export default function SurveyInvitations() {
  const [invitations, setInvitations] = useState<SurveyInvitation[]>([])
  const [surveys, setSurveys] = useState<Survey[]>([])
  const [search, setSearch] = useState('')
  const [state, setState] = useState<SurveyInvitationStateName | ''>('')
  const [survey, setSurvey] = useState('')
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
        const filters: SurveyInvitationFilters = {
          search: search || undefined,
          state,
          survey: survey || undefined,
          page: targetPage,
        }
        const response = await listSurveyInvitations(filters)
        setInvitations(response.data)
        setPage(response.meta.current_page)
        setLastPage(response.meta.last_page)
        setTotal(response.meta.total)
      } catch {
        setError('Could not load the invitations. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [search, state, survey],
  )

  useEffect(() => {
    void load(1)
  }, [load])

  // The survey picker reads the library once; an invitation of a retired survey still resolves on
  // its own row, so the picker does not need them.
  useEffect(() => {
    void (async () => {
      try {
        const response = await listSurveys({})
        setSurveys(response.data)
      } catch {
        // The book still works without the picker.
      }
    })()
  }, [])

  const onCopy = async (invitation: SurveyInvitation) => {
    if (!invitation.public_url) return

    try {
      await navigator.clipboard.writeText(invitation.public_url)
      setNotice(`Link copied — it opens the survey sent to ${invitation.company?.name ?? 'the client'}.`)
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
          ? `Sent again to ${updated.recipient_email} with a fresh window.`
          : `The mailbox rejected it again: ${updated.failed_reason ?? 'no reason given'}.`,
      )
      await load(page)
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
      setNotice(`Withdrawn — the link sent to ${invitation.recipient_email} no longer works.`)
      await load(page)
    } catch {
      setError('Could not withdraw that invitation.')
    } finally {
      setSaving('')
    }
  }

  const columns: DataTableColumn<SurveyInvitation>[] = [
    {
      key: 'reference',
      header: 'Reference',
      className: 'whitespace-nowrap font-mono text-xs text-muted-foreground',
      render: (invitation) => invitation.reference,
    },
    {
      key: 'survey',
      header: 'Survey',
      render: (invitation) => (
        <div className="min-w-0">
          <p className="font-medium text-foreground">{invitation.survey?.name ?? '—'}</p>
          <p className="text-xs text-muted-foreground">{invitation.company?.name ?? '—'}</p>
        </div>
      ),
    },
    {
      key: 'recipient',
      header: 'Recipient',
      render: (invitation) => (
        <div className="min-w-0">
          <p className="text-foreground">{invitation.recipient_name ?? '—'}</p>
          <p className="break-all text-xs text-muted-foreground">{invitation.recipient_email}</p>
        </div>
      ),
    },
    {
      key: 'state',
      header: 'State',
      className: 'whitespace-nowrap',
      render: (invitation) => (
        <StatusBadge tone={surveyInvitationStateMeta[invitation.state].tone}>
          {invitation.state_label}
        </StatusBadge>
      ),
    },
    {
      key: 'sent_at',
      header: 'Sent',
      className: 'whitespace-nowrap text-muted-foreground',
      render: (invitation) => (invitation.sent_at ? formatDateTime(invitation.sent_at) : '—'),
    },
    {
      key: 'expires_at',
      header: 'Closes',
      className: 'whitespace-nowrap text-muted-foreground',
      render: (invitation) =>
        invitation.submitted_at
          ? `Answered ${formatDateTime(invitation.submitted_at)}`
          : invitation.expires_at
            ? formatDate(invitation.expires_at)
            : '—',
    },
    {
      key: 'actions',
      header: '',
      render: (invitation) => (
        <div className="flex items-center justify-end gap-3">
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
      ),
    },
  ]

  return (
    <div>
      <PageHeader
        title="Survey invitations"
        description="Every survey sent to a client, the link it carried, and what came back — scoped to the accounts you can see."
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
            placeholder="Search the reference, recipient, account or survey"
            className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          value={state}
          onChange={(event) => setState(event.target.value as SurveyInvitationStateName | '')}
          aria-label="Filter by state"
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">Every state</option>
          {surveyInvitationStates.map((value) => (
            <option key={value} value={value}>
              {surveyInvitationStateMeta[value].label}
            </option>
          ))}
        </select>

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

        <button
          type="submit"
          className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
          Filter
        </button>
      </form>

      <DataTable
        columns={columns}
        rows={invitations}
        rowKey={(invitation) => invitation.id}
        loading={loading}
        loadingLabel="Loading invitations…"
        pagination={{
          currentPage: page,
          lastPage,
          total,
          onPageChange: (next) => void load(next),
        }}
        empty={
          <EmptyState
            title="No surveys sent yet"
            description="Open an account and use its Satisfaction tab to send one of the surveys in the library."
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
