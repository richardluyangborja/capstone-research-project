import PageHeader from '../../components/common/PageHeader'
import EmptyState from '../../components/common/EmptyState'

interface AdminPlaceholderProps {
  title: string
  description: string
  /** The slice that will fill this screen, so the shell never implies a control already exists. */
  slice: string
  detail: string
}

/**
 * A deliberately inert administration screen (spec 19 S1). The route and navigation exist now;
 * the controls arrive with the slice named here, so the shell never ships a fake button.
 */
export default function AdminPlaceholder({
  title,
  description,
  slice,
  detail,
}: AdminPlaceholderProps) {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={title} description={description} />
      <EmptyState title={`Arrives with ${slice}`} description={detail} />
    </div>
  )
}
