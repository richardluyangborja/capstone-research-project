import { describe, expect, it } from 'vitest'
import {
  ADMIN_ROLES,
  PLATFORM_ROLES,
  adminAreas,
  canAccessAdmin,
  canAccessPersonalSettings,
  canAccessPlatform,
  hasAnyRole,
} from './permissions'

describe('administration capability helpers', () => {
  it('keeps the application plane to admins and the platform plane to superadmins', () => {
    expect(ADMIN_ROLES).toEqual(['admin', 'superadmin'])
    expect(PLATFORM_ROLES).toEqual(['superadmin'])

    expect(canAccessAdmin('admin')).toBe(true)
    expect(canAccessAdmin('superadmin')).toBe(true)
    expect(canAccessAdmin('manager')).toBe(false)
    expect(canAccessAdmin('salesrep')).toBe(false)

    expect(canAccessPlatform('superadmin')).toBe(true)
    expect(canAccessPlatform('admin')).toBe(false)
    expect(canAccessPlatform('manager')).toBe(false)
  })

  it('gives every signed-in role personal settings', () => {
    ;(['salesrep', 'manager', 'admin', 'superadmin'] as const).forEach((role) => {
      expect(canAccessPersonalSettings(role)).toBe(true)
    })

    expect(canAccessPersonalSettings(null)).toBe(false)
  })

  it('returns the visible administration areas for each role', () => {
    expect(adminAreas('salesrep')).toEqual([])
    expect(adminAreas('manager')).toEqual([])
    expect(adminAreas('admin')).toEqual(['application'])
    expect(adminAreas('superadmin')).toEqual(['application', 'platform'])
  })

  it('matches a role against an explicit allow-list', () => {
    expect(hasAnyRole('manager', ['salesrep', 'manager'])).toBe(true)
    expect(hasAnyRole(null, ['salesrep'])).toBe(false)
  })
})
