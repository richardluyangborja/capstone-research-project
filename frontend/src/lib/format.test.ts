import { describe, expect, it } from 'vitest'
import { formatCurrency, formatDate, toDateTimeLocal } from './format'

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
