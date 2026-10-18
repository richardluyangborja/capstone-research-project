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
