import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdminSettings from './admin/AdminSettings'
import type { AdminSettingCategory, Role } from '../types'

// `vi.mock` factories hoist above the imports, so the fixtures they close over use `vi.hoisted`.
const { categories, settingsApi, useAuth } = vi.hoisted(() => {
  const categories: AdminSettingCategory[] = [
    {
      category: 'crm',
      label: 'CRM behavior',
      settings: [
        {
          key: 'crm.aging.stale_days',
          label: 'Lead stale threshold (days)',
          type: 'int',
          options: null,
          value: 14,
          source: 'default',
          scope: 'system',
          write_role: 'admin',
          read_only: false,
          editable: true,
        },
        {
          key: 'crm.integrations.email.simulate_failure',
          label: 'Mocked email — simulate failures',
          type: 'bool',
          options: null,
          value: false,
          source: 'database',
          scope: 'system',
          write_role: 'admin',
          read_only: false,
          editable: true,
        },
        {
          key: 'organization.currency',
          label: 'Currency',
          type: 'enum',
          options: ['PHP'],
          value: 'PHP',
          source: 'default',
          scope: 'organization',
          write_role: null,
          read_only: true,
          editable: false,
        },
      ],
    },
  ]

  return {
    categories,
    useAuth: vi.fn(),
    settingsApi: {
      getAdminSettings: vi.fn().mockResolvedValue(categories),
      updateAdminSettings: vi.fn().mockResolvedValue(categories),
      refreshAdminSettings: vi.fn().mockResolvedValue(undefined),
    },
  }
})

vi.mock('../lib/admin', () => settingsApi)
vi.mock('../lib/auth-context', () => ({ useAuth }))

const renderSettings = (role: Role = 'admin') => {
  useAuth.mockReturnValue({
    user: { id: 1, name: 'Ada Admin', email: 'ada@primepower.test', role },
    token: 'token-1',
    login: vi.fn(),
    logout: vi.fn(),
  })

  return render(
    <MemoryRouter>
      <AdminSettings />
    </MemoryRouter>,
  )
}

describe('admin settings', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    settingsApi.getAdminSettings.mockResolvedValue(categories)
    settingsApi.updateAdminSettings.mockResolvedValue(categories)
  })

  it('renders the grouped settings with their source badges', async () => {
    renderSettings()

    expect(await screen.findByRole('heading', { name: 'CRM behavior' })).toBeInTheDocument()
    expect(screen.getByText('crm.aging.stale_days')).toBeInTheDocument()
    expect(screen.getAllByText('Default')).toHaveLength(2)
    expect(screen.getByText('Database')).toBeInTheDocument()
    expect(screen.getByText('Read-only')).toBeInTheDocument()
  })

  it('saves only the changed keys and reports success', async () => {
    renderSettings()

    const input = await screen.findByLabelText('Lead stale threshold (days)')
    fireEvent.change(input, { target: { value: '7' } })

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => {
      expect(settingsApi.updateAdminSettings).toHaveBeenCalledWith({ 'crm.aging.stale_days': 7 })
    })
    expect(
      await screen.findByText('Settings saved. The new values apply from the next request.'),
    ).toBeInTheDocument()
  })

  it('surfaces per-key validation errors from a rejected save', async () => {
    settingsApi.updateAdminSettings.mockRejectedValue({
      response: {
        status: 422,
        data: {
          message: 'Please correct the highlighted fields.',
          errors: { 'settings.crm.aging.stale_days': ['The stale threshold must be at least 1.'] },
        },
      },
    })

    renderSettings()

    const input = await screen.findByLabelText('Lead stale threshold (days)')
    fireEvent.change(input, { target: { value: '0' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(
      await screen.findByText('The stale threshold must be at least 1.'),
    ).toBeInTheDocument()
  })

  it('confirms before refreshing the configuration', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    renderSettings()

    fireEvent.click(await screen.findByRole('button', { name: 'Refresh configuration' }))

    expect(confirm).toHaveBeenCalledOnce()
    expect(settingsApi.refreshAdminSettings).not.toHaveBeenCalled()

    confirm.mockReturnValue(true)
    fireEvent.click(screen.getByRole('button', { name: 'Refresh configuration' }))

    await waitFor(() => {
      expect(settingsApi.refreshAdminSettings).toHaveBeenCalledOnce()
    })
    expect(
      await screen.findByText('The effective configuration was refreshed.'),
    ).toBeInTheDocument()

    confirm.mockRestore()
  })

  it('hides the Security category from an admin and shows it to a superadmin', async () => {
    const security: AdminSettingCategory = {
      category: 'security',
      label: 'Security',
      settings: [
        {
          key: 'security.session_lifetime_minutes',
          label: 'Session lifetime (minutes)',
          type: 'int',
          options: null,
          value: 480,
          source: 'default',
          scope: 'system',
          write_role: 'superadmin',
          read_only: false,
          editable: true,
        },
      ],
    }
    settingsApi.getAdminSettings.mockResolvedValue([...categories, security])

    const { unmount } = renderSettings('admin')
    expect(await screen.findByRole('heading', { name: 'CRM behavior' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Security' })).not.toBeInTheDocument()
    unmount()

    renderSettings('superadmin')
    expect(await screen.findByRole('heading', { name: 'Security' })).toBeInTheDocument()
  })
})
