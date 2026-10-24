// PH-based, single-currency formatting (ADR-0007). Currency is always PHP.
const LOCALE = 'en-PH'
const TIME_ZONE = 'Asia/Manila'

export const formatCurrency = (value: number | string | null | undefined): string =>
  new Intl.NumberFormat(LOCALE, {
    style: 'currency',
    currency: 'PHP',
  }).format(Number(value ?? 0))

export const formatDate = (value: string | Date | null | undefined): string => {
  if (!value) return ''
  return new Intl.DateTimeFormat(LOCALE, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    timeZone: TIME_ZONE,
  }).format(new Date(value))
}

export const formatDateTime = (value: string | Date | null | undefined): string => {
  if (!value) return ''
  return new Intl.DateTimeFormat(LOCALE, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: TIME_ZONE,
  }).format(new Date(value))
}

/**
 * A recent instant as a person reads it ("2 hours ago"). Older than a week, it falls back to
 * the PH business date; the dashboard activity rail is the caller (spec 14).
 */
export const formatRelativeTime = (
  value: string | Date | null | undefined,
  now: Date = new Date(),
): string => {
  if (!value) return ''

  const date = new Date(value)
  const seconds = Math.round((now.getTime() - date.getTime()) / 1000)
  const future = seconds < 0
  const absolute = Math.abs(seconds)

  if (absolute < 60) return future ? 'in a moment' : 'just now'

  const [amount, unit] =
    absolute < 3600
      ? [Math.floor(absolute / 60), 'minute']
      : absolute < 86400
        ? [Math.floor(absolute / 3600), 'hour']
        : [Math.floor(absolute / 86400), 'day']

  if (unit === 'day' && amount >= 7) return formatDate(value)

  const label = `${amount} ${unit}${amount === 1 ? '' : 's'}`

  return future ? `in ${label}` : `${label} ago`
}

/**
 * A file's size as a person reads it. The API sends raw bytes; the display never shows them
 * (module 03 slice S3).
 */
export const formatFileSize = (bytes: number | null | undefined): string => {
  const size = Number(bytes ?? 0)

  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${Math.round(size / 1024)} KB`

  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

/**
 * An ISO instant (as the API returns it) → the local wall-clock string a `datetime-local`
 * input expects. Kept local on purpose: the input has no zone, so converting back through
 * `new Date(...)` round-trips the same instant.
 */
export const toDateTimeLocal = (value: string | Date | null | undefined): string => {
  if (!value) return ''
  const date = new Date(value)
  const pad = (part: number) => String(part).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(
    date.getHours(),
  )}:${pad(date.getMinutes())}`
}
