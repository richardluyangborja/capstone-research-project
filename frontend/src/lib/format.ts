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
