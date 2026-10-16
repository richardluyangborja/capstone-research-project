import { describe, expect, it } from 'vitest'
import { formatCurrency, formatDate } from './format'

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
