import type { ReactNode } from 'react'
import StatusBadge from '../common/StatusBadge'

interface PreviewFrameProps {
  title: string
  caption: string
  children: ReactNode
  className?: string
}

/**
 * A framed "screenshot" for the public landing page (spec 16 §Landing page).
 *
 * The frame is real HTML/CSS, never an image, so it stays sharp and theme-aware. The table is
 * illustrative, so it is hidden from assistive technology; the caption names what it shows.
 */
function PreviewFrame({ title, caption, children, className }: PreviewFrameProps) {
  return (
    <figure
      className={`overflow-hidden rounded-xl border border-border bg-card shadow-sm ${className ?? ''}`}
    >
      <div
        aria-hidden="true"
        className="flex items-center gap-1.5 border-b border-border bg-secondary/70 px-3 py-2"
      >
        <span className="h-2 w-2 rounded-full bg-destructive/70" />
        <span className="h-2 w-2 rounded-full bg-rating-warm/80" />
        <span className="h-2 w-2 rounded-full bg-people/80" />
        <span className="ml-2 truncate text-xs font-medium text-muted-foreground">{title}</span>
      </div>
      <div aria-hidden="true" className="overflow-x-auto p-3 sm:p-4">
        {children}
      </div>
      <figcaption className="sr-only">{caption}</figcaption>
    </figure>
  )
}

const th = 'whitespace-nowrap px-2 py-1.5 text-left text-xs font-medium text-muted-foreground'
const td = 'whitespace-nowrap px-2 py-2 text-xs text-foreground'

export function LeadWorkspacePreview({ className }: { className?: string }) {
  return (
    <PreviewFrame
      className={className}
      title="Lead workspace"
      caption="Product preview: the lead workspace showing captured leads, ratings and owners."
    >
      <table className="w-full min-w-max text-left">
        <thead className="border-b border-border">
          <tr>
            <th className={th}>Lead</th>
            <th className={th}>Rating</th>
            <th className={th}>Status</th>
            <th className={th}>Owner</th>
          </tr>
        </thead>
        <tbody>
          <tr className="border-b border-border last:border-0">
            <td className={td}>Sunrise Foods Inc</td>
            <td className={td}>
              <StatusBadge tone="hot">Hot</StatusBadge>
            </td>
            <td className={td}>Qualified</td>
            <td className={td}>Sam</td>
          </tr>
          <tr className="border-b border-border last:border-0">
            <td className={td}>Northpoint Retail</td>
            <td className={td}>
              <StatusBadge tone="warm">Warm</StatusBadge>
            </td>
            <td className={td}>Contacted</td>
            <td className={td}>Liza</td>
          </tr>
          <tr>
            <td className={td}>Metro Builders</td>
            <td className={td}>
              <StatusBadge tone="cold">Cold</StatusBadge>
            </td>
            <td className={td}>New</td>
            <td className={td}>Unassigned</td>
          </tr>
        </tbody>
      </table>
    </PreviewFrame>
  )
}

export function AccountHealthPreview({ className }: { className?: string }) {
  return (
    <PreviewFrame
      className={className}
      title="Account 360 · health"
      caption="Product preview: an account health view with AR, contract and deployment flags."
    >
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-semibold text-foreground">BPO Solutions PH</p>
          <p className="text-xs text-muted-foreground">CO-2026-0002 · Onboarding</p>
        </div>
        <StatusBadge tone="danger">At risk</StatusBadge>
      </div>
      <table className="w-full min-w-max text-left">
        <thead className="border-b border-border">
          <tr>
            <th className={th}>Signal</th>
            <th className={th}>Source</th>
            <th className={th}>Action</th>
          </tr>
        </thead>
        <tbody>
          <tr className="border-b border-border last:border-0">
            <td className={td}>Overdue AR</td>
            <td className={td}>Finance</td>
            <td className={td}>Review billing</td>
          </tr>
          <tr className="border-b border-border last:border-0">
            <td className={td}>Deployment 0% filled</td>
            <td className={td}>Deployment</td>
            <td className={td}>Open delivery</td>
          </tr>
          <tr>
            <td className={td}>Contract expiring</td>
            <td className={td}>Contracts</td>
            <td className={td}>Renew</td>
          </tr>
        </tbody>
      </table>
    </PreviewFrame>
  )
}

export function PipelinePreview({ className }: { className?: string }) {
  return (
    <PreviewFrame
      className={className}
      title="Pipeline forecast"
      caption="Product preview: the pipeline forecast showing open stages, values and weighted totals."
    >
      <table className="w-full min-w-max text-left">
        <thead className="border-b border-border">
          <tr>
            <th className={th}>Stage</th>
            <th className={th}>Deals</th>
            <th className={th}>Value</th>
            <th className={th}>Weighted</th>
          </tr>
        </thead>
        <tbody>
          <tr className="border-b border-border last:border-0">
            <td className={td}>Negotiation</td>
            <td className={td}>4</td>
            <td className={td}>₱2.45M</td>
            <td className={td}>₱1.47M</td>
          </tr>
          <tr className="border-b border-border last:border-0">
            <td className={td}>Proposal sent</td>
            <td className={td}>6</td>
            <td className={td}>₱1.67M</td>
            <td className={td}>₱835k</td>
          </tr>
          <tr>
            <td className={td}>Qualified</td>
            <td className={td}>9</td>
            <td className={td}>₱980k</td>
            <td className={td}>₱196k</td>
          </tr>
        </tbody>
      </table>
      <div className="mt-3 grid grid-cols-2 gap-2 border-t border-border pt-3">
        <div>
          <p className="text-xs text-muted-foreground">Open value</p>
          <p className="text-sm font-semibold text-cost">₱5.10M</p>
        </div>
        <div>
          <p className="text-xs text-muted-foreground">Win rate</p>
          <p className="text-sm font-semibold text-people">42%</p>
        </div>
      </div>
    </PreviewFrame>
  )
}
