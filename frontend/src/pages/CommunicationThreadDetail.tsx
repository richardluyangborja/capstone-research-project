import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowDownLeft, ArrowLeft, ArrowUpRight, MessageSquareReply, Paperclip } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import StatusBadge from '../components/common/StatusBadge'
import AlertItem from '../components/common/AlertItem'
import { getCommunicationThread } from '../lib/communications'
import {
  communicationChannelMeta,
  communicationDirectionMeta,
  communicationRecordPath,
  communicationStatusMeta,
  communicationSubjectLabels,
} from '../lib/communicationMeta'
import { formatDateTime } from '../lib/format'
import type { Communication, CommunicationThreadDetail as Thread } from '../types'

// Module 03 §UI (slice S4) — one conversation read as it happened: the original first, each
// answer after it, with the message each one answers named. Anything the API says may not be
// answered (a draft, a send the gateway rejected) offers no reply control.
export default function CommunicationThreadDetail() {
  const { threadId = '' } = useParams()

  const [thread, setThread] = useState<Thread | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      setThread(await getCommunicationThread(threadId))
    } catch {
      setError('Could not load this conversation.')
    } finally {
      setLoading(false)
    }
  }, [threadId])

  useEffect(() => {
    void load()
  }, [load])

  if (loading) {
    return <p className="text-sm text-muted-foreground">Loading conversation…</p>
  }

  if (!thread) {
    return (
      <div>
        <AlertItem variant="danger">{error || 'Conversation not found.'}</AlertItem>
        <Link
          to="/communications/threads"
          className="mt-4 inline-block text-sm font-medium text-counts"
        >
          Back to the conversations
        </Link>
      </div>
    )
  }

  const recordHref = communicationRecordPath(thread.record)
  const lastMessage = thread.messages[thread.messages.length - 1]
  const openReply = lastMessage?.can_reply && lastMessage.deleted_at === null

  return (
    <div>
      <PageHeader
        title={thread.title ?? 'Conversation'}
        description={`${thread.message_count} ${
          thread.message_count === 1 ? 'message' : 'messages'
        } · ${formatDateTime(thread.first_at)} → ${formatDateTime(thread.last_at)}`}
        actions={
          <>
            <Link
              to="/communications/threads"
              className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
            >
              <ArrowLeft className="h-4 w-4" aria-hidden="true" />
              Conversations
            </Link>
            {openReply && (
              <Link
                to={`/communications/new?reply_to=${lastMessage.id}`}
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
              >
                <MessageSquareReply className="h-4 w-4" aria-hidden="true" />
                Reply
              </Link>
            )}
          </>
        }
      />

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="flex flex-col gap-4 lg:col-span-2">
          {thread.messages.map((message, index) => (
            <ThreadMessage
              key={message.id}
              communication={message}
              first={index === 0}
              answer={
                thread.messages.find((candidate) => candidate.id === message.in_reply_to_id) ?? null
              }
            />
          ))}
        </div>

        <div className="flex flex-col gap-6">
          <FormCard title="Conversation">
            <dl className="flex flex-col gap-3 text-sm">
              <div>
                <dt className="text-xs font-medium text-muted-foreground">
                  {thread.record ? communicationSubjectLabels[thread.record.type] : 'Record'}
                </dt>
                <dd className="text-foreground">
                  {thread.record ? (
                    recordHref ? (
                      <Link to={recordHref} className="font-medium text-counts">
                        {thread.record.label ?? thread.record.id}
                      </Link>
                    ) : (
                      (thread.record.label ?? thread.record.id)
                    )
                  ) : (
                    '—'
                  )}
                </dd>
              </div>
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Channel</dt>
                <dd className="text-foreground">
                  {thread.channel ? communicationChannelMeta[thread.channel].label : '—'}
                </dd>
              </div>
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Started</dt>
                <dd className="text-foreground">{formatDateTime(thread.first_at)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Last message</dt>
                <dd className="text-foreground">{formatDateTime(thread.last_at)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Messages</dt>
                <dd className="text-foreground">{thread.message_count}</dd>
              </div>
            </dl>

            <p className="mt-4 border-t border-border pt-4 text-xs text-muted-foreground">
              A reply joins the conversation it answers and cannot move to another record. Open the{' '}
              <Link to="/communications" className="font-medium text-counts">
                history book
              </Link>{' '}
              to see these messages beside everything else logged against the record.
            </p>
          </FormCard>
        </div>
      </div>
    </div>
  )
}

function ThreadMessage({
  communication,
  first,
  answer,
}: {
  communication: Communication
  first: boolean
  /** The message this one answers, when it names one inside the conversation. */
  answer: Communication | null
}) {
  const channel = communicationChannelMeta[communication.channel]
  const direction = communicationDirectionMeta[communication.direction]
  const status = communicationStatusMeta[communication.status]
  const DirectionIcon = communication.direction === 'inbound' ? ArrowDownLeft : ArrowUpRight
  const counterpart =
    communication.direction === 'inbound' ? communication.from_name : communication.to_name
  const replyable = communication.can_reply && communication.deleted_at === null

  return (
    <section className="rounded-lg border border-border bg-card">
      <header className="border-b border-border px-4 py-3">
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge tone={channel.tone}>{channel.label}</StatusBadge>
          <StatusBadge tone={direction.tone}>
            <span className="inline-flex items-center gap-1">
              <DirectionIcon className="h-3 w-3" aria-hidden />
              {direction.label}
            </span>
          </StatusBadge>
          <StatusBadge tone={status.tone}>{status.label}</StatusBadge>
          {first && <span className="text-xs text-muted-foreground">Started the conversation</span>}
          <span className="ml-auto font-mono text-xs text-muted-foreground">
            {communication.reference}
          </span>
        </div>
        <h3 className="mt-2 text-sm font-semibold text-foreground">
          <Link to={`/communications/${communication.id}`} className="hover:underline">
            {communication.subject}
          </Link>
        </h3>
        <p className="mt-1 text-xs text-muted-foreground">
          {formatDateTime(communication.occurred_at)}
          {counterpart ? ` · ${counterpart}` : ''}
          {communication.owner ? ` · logged by ${communication.owner.name}` : ''}
          {answer ? ` · answers ${answer.reference}` : ''}
          {communication.attachments_count
            ? ` · ${communication.attachments_count} ${
                communication.attachments_count === 1 ? 'file' : 'files'
              }`
            : ''}
        </p>
      </header>

      <div className="px-4 py-3">
        {communication.body ? (
          <p className="whitespace-pre-wrap text-sm text-foreground">{communication.body}</p>
        ) : (
          <p className="text-sm text-muted-foreground">Nothing was written down.</p>
        )}

        {communication.status === 'draft' && (
          <p className="mt-3 text-xs text-muted-foreground">
            Draft — written but not sent, so it is not on the record's timeline yet.
          </p>
        )}

        {communication.status === 'failed' && (
          <p className="mt-3 text-xs text-destructive">
            {communication.failed_reason ?? 'The gateway rejected this message.'}
          </p>
        )}
      </div>

      <footer className="flex flex-wrap items-center gap-3 border-t border-border px-4 py-3 text-sm">
        <Link to={`/communications/${communication.id}`} className="font-medium text-counts">
          Open message
        </Link>
        {communication.attachments_count ? (
          <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
            <Paperclip className="h-3 w-3" aria-hidden />
            {communication.attachments_count}{' '}
            {communication.attachments_count === 1 ? 'file' : 'files'}
          </span>
        ) : null}
        {replyable && (
          <Link
            to={`/communications/new?reply_to=${communication.id}`}
            className="ml-auto inline-flex items-center gap-1.5 font-medium text-counts"
          >
            <MessageSquareReply className="h-4 w-4" aria-hidden />
            Reply
          </Link>
        )}
      </footer>
    </section>
  )
}
