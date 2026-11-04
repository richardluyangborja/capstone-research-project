import { describe, expect, it } from 'vitest'
import { afterLoginPath, loginPath, safeNextPath } from './authRedirect'

describe('auth redirects', () => {
  it('accepts internal paths and keeps their query and hash', () => {
    expect(safeNextPath('/leads?view=mine#follow-up')).toBe('/leads?view=mine#follow-up')
    expect(safeNextPath('/accounts/abc?tab=commercial')).toBe('/accounts/abc?tab=commercial')
  })

  it('rejects external, protocol-relative and sign-in destinations', () => {
    expect(safeNextPath(null)).toBeNull()
    expect(safeNextPath('https://evil.example')).toBeNull()
    expect(safeNextPath('//evil.example')).toBeNull()
    expect(safeNextPath('/\\evil.example')).toBeNull()
    expect(safeNextPath('/login?next=/leads')).toBeNull()
    expect(safeNextPath('/?login=1&next=/leads')).toBeNull()
  })

  it('builds the linkable sign-in surface', () => {
    expect(loginPath()).toBe('/?login=1')
    expect(loginPath('/leads?view=mine')).toBe('/?login=1&next=%2Fleads%3Fview%3Dmine')
    expect(loginPath('https://evil.example')).toBe('/?login=1')
  })

  it('falls back to the dashboard after login', () => {
    expect(afterLoginPath('/leads?view=mine')).toBe('/leads?view=mine')
    expect(afterLoginPath(null)).toBe('/dashboard')
    expect(afterLoginPath('/login')).toBe('/dashboard')
  })
})
