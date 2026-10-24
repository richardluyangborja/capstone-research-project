import { describe, expect, it } from 'vitest'
import {
  formatCurrency,
  formatDate,
  formatFileSize,
  formatRelativeTime,
  toDateTimeLocal,
} from './format'

describe('formatCurrency', () => {
  it('formats PHP amounts with the peso currency', () => {
    expect(formatCurrency(1234.5)).toContain('1,234.50')
  })
})

describe('formatDate', () => {
  it('formats dates in Asia/Manila', () => {
    const formatted = formatDate('2026-01-15')
    expect(formatted).toContain('2026')
    expect(formatted).toMatch(/Jan/)
  })
})

describe('formatFileSize', () => {
  it('reads a byte count the way a person would', () => {
    expect(formatFileSize(0)).toBe('0 B')
    expect(formatFileSize(512)).toBe('512 B')
    expect(formatFileSize(120 * 1024)).toBe('120 KB')
    expect(formatFileSize(3.5 * 1024 * 1024)).toBe('3.5 MB')
  })
})

describe('formatRelativeTime', () => {
  const now = new Date('2026-10-24T04:00:00Z')

  it('reads recent instants the way a person would', () => {
    expect(formatRelativeTime('2026-10-24T03:59:40Z', now)).toBe('just now')
    expect(formatRelativeTime('2026-10-24T03:58:00Z', now)).toBe('2 minutes ago')
    expect(formatRelativeTime('2026-10-24T02:00:00Z', now)).toBe('2 hours ago')
    expect(formatRelativeTime('2026-10-23T04:00:00Z', now)).toBe('1 day ago')
  })

  it('falls back to the PH business date after a week', () => {
    const formatted = formatRelativeTime('2026-10-10T04:00:00Z', now)

    expect(formatted).toContain('2026')
    expect(formatted).toMatch(/Oct/)
  })
})

describe('toDateTimeLocal', () => {
  it('returns an empty string for nothing, and the same instant otherwise', () => {
    expect(toDateTimeLocal(null)).toBe('')

    const iso = '2026-10-18T02:30:00.000000Z'
    const local = toDateTimeLocal(iso)
    expect(local).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/)
    // Parsed back locally, it is the same instant the API returned.
    expect(new Date(local).toISOString()).toBe(new Date(iso).toISOString())
  })
})
