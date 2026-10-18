import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowDownLeft, ArrowUpRight, MailPlus } from 'lucide-react'
import AlertItem from './AlertItem'
import StatusBadge from './StatusBadge'
import { listCommunicationsFor } from '../../lib/communications'
import { communicationChannelMeta, communicationStatusMeta } from '../../lib/communicationMeta'
import { formatDateTime } from '../../lib/format'
import type { Communication, CommunicationSubjectType } from '../../types'

interface CommunicationListProps {
  subjectType: CommunicationSubjectType
  subjectId: string
  /** Hide the log action for a caller who may not work the record (the API enforces it anyway). */
  canLog?: boolean
}

/**
 * One record's communication history (module 03 §UI) — the shared panel behind the account
 * tab and the lead/deal sections, so the three pages render the same list. It fetches its own
 * data: the subject pages already have enough to load.
 */
export default function CommunicationList({
  subjectType,
  subjectId,
  canLog = true,
}: CommunicationListProps) {
  const [communications, setCommunications] = useState<Communication[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const history = await listCommunicationsFor(subjectType, subjectId)
      setCommunications(history.data)
    } catch {
      setError('Could not load the communication history.')
    } finally {
      setLoading(false)
    }
  }, [subjectType, subjectId])

  useEffect(() => {
    void load()
  }, [load])

  const logHref = `/communications/new?subject_type=${subjectType}&subject_id=${subjectId}`
  const bookHref = `/communications?subject_type=${subjectType}&subject_id=${subjectId}`

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          {communications.length === 0
            ? 'Calls, emails, meetings and letters logged against this record.'
            : `${communications.length} entr${communications.length === 1 ? 'y' : 'ies'}, newest first.`}
        </p>
        <div className="flex items-center gap-3">
          <Link to={bookHref} className="text-sm font-medium text-counts">
            Open in history book
          </Link>
          {canLog && (
            <Link
              to={logHref}
              className="inline-flex items-center gap-1.5 rounded-md bg-counts px-3 py-1.5 text-sm font-medium text-white"
            >
              <MailPlus className="h-4 w-4" aria-hidden />
              Log communication
            </Link>
          )}
        </div>
      </div>

      {error && <AlertItem variant="danger">{error}</AlertItem>}

      {loading ? (
        <p className="text-sm text-muted-foreground">Loading history…</p>
      ) : communications.length === 0 ? (
        <p className="text-sm text-muted-foreground">Nothing logged yet.</p>
      ) : (
        <ul className="flex flex-col gap-4">
          {communications.map((communication) => (
            <CommunicationRow key={communication.id} communication={communication} />
          ))}
        </ul>
      )}
    </div>
  )
}

function CommunicationRow({ communication }: { communication: Communication }) {
  const channel = communicationChannelMeta[communication.channel]
  const status = communicationStatusMeta[communication.status]
  const inbound = communication.direction === 'inbound'
  const DirectionIcon = inbound ? ArrowDownLeft : ArrowUpRight
  const counterpart = inbound ? communication.from_name : communication.to_name

  return (
    <li className="flex gap-3">
      <StatusBadge tone={channel.tone}>{channel.label}</StatusBadge>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <Link to={`/communications/${communication.id}`} className="font-medium text-foreground">
            {communication.subject}
          </Link>
          <StatusBadge tone={status.tone}>{status.label}</StatusBadge>
          <StatusBadge tone="neutral">
            <span className="inline-flex items-center gap-1">
              <DirectionIcon className="h-3 w-3" aria-hidden />
              {inbound ? 'Inbound' : 'Outbound'}
            </span>
          </StatusBadge>
          {communication.outcome && (
            <span className="text-xs text-muted-foreground">
              {communication.outcome.replace('_', ' ')}
              {communication.duration_minutes ? ` · ${communication.duration_minutes} min` : ''}
            </span>
          )}
        </div>
        <p className="mt-1 text-xs text-muted-foreground">
          {formatDateTime(communication.occurred_at)}
          {counterpart ? ` · ${counterpart}` : ''}
          {communication.owner ? ` · ${communication.owner.name}` : ''}
          {communication.attachments_count
            ? ` · ${communication.attachments_count} ${
                communication.attachments_count === 1 ? 'file' : 'files'
              }`
            : ''}
        </p>
        {communication.body && (
          <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">{communication.body}</p>
        )}
      </div>
    </li>
  )
}
