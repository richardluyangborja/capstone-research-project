import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { Info } from 'lucide-react'

interface InfoPopoverProps {
  /** Visible badge text; also the accessible name of the toggle. */
  label: string
  /** Heading shown above the explanation. */
  title?: string
  /** The explanation. Keep it short — the panel is not a dialog. */
  children: ReactNode
  className?: string
}

/**
 * A labelled info badge that discloses its explanation on click or keyboard activation.
 *
 * Disclosure pattern rather than a tooltip: the panel is opened deliberately, carries
 * `aria-expanded`/`aria-controls`, and closes on Escape or an outside click, so the
 * explanation stays reachable for keyboard and touch users.
 */
export default function InfoPopover({ label, title, children, className }: InfoPopoverProps) {
  const [open, setOpen] = useState(false)
  const panelId = useId()
  const containerRef = useRef<HTMLSpanElement>(null)

  useEffect(() => {
    if (!open) {
      return
    }

    const handlePointerDown = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false)
      }
    }

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [open])

  return (
    <span ref={containerRef} className={`relative inline-flex ${className ?? ''}`}>
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-controls={panelId}
        aria-label={`${label} — how is this calculated?`}
        className="inline-flex items-center gap-1 rounded-full border border-border bg-muted/40 px-2 py-0.5 text-xs font-medium text-muted-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <Info className="h-3.5 w-3.5" aria-hidden="true" />
        {label}
      </button>
      {open && (
        <span
          id={panelId}
          className="absolute left-0 top-full z-20 mt-2 block w-64 rounded-lg border border-border bg-card p-3 text-xs text-muted-foreground shadow-lg"
        >
          {title && <span className="mb-1 block font-semibold text-foreground">{title}</span>}
          {children}
        </span>
      )}
    </span>
  )
}
