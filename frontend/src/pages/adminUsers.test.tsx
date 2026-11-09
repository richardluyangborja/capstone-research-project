import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdminUsers from './admin/AdminUsers'
import type { AdminTeamOption, AdminUser, Paginated, Role } from '../types'

// `vi.mock` factories hoist above the imports, so the fixtures they close over use `vi.hoisted`.
const { directory, teams, adminApi, useAuth } = vi.hoisted(() => {
  const abilities = {
    view: true,
    update: true,
    change_role: true,
    change_team: true,
    suspend: true,
    restore: false,
    resend_invitation: true,
    view_ownership_impact: true,
    can_assign_admin: false,
  }

  const rep: AdminUser = {
    id: '01JUSERREP0000000000000000',
    name: 'Jordan Sales',
    email: 'jordan@primepower.test',
    role: 'salesrep',
    status: 'active',
    team: { id: '01JTEAM0000000000000000001', name: 'Business Development' },
    suspended_at: null,
    suspended_by: null,
    status_reason: null,
    created_at: '2026-10-15T03:51:40.000000Z',
    can: abilities,
  }

  const suspended: AdminUser = {
    ...rep,
    id: '01JUSERSUS0000000000000000',
    name: 'Dana Suspended',
    email: 'dana@primepower.test',
    status: 'suspended',
    suspended_at: '2026-11-01T02:00:00.000000Z',
    suspended_by: { id: '01JUSERADMIN00000000000000', name: 'Ada Admin' },
    status_reason: 'Investigating access.',
    can: { ...abilities, suspend: false, restore: true },
  }

  const page: Paginated<AdminUser> = {
    data: [rep, suspended],
    meta: { current_page: 1, per_page: 15, total: 2, last_page: 1 },
    links: {},
  }

  const teamOptions: AdminTeamOption[] = [
    { id: '01JTEAM0000000000000000001', name: 'Business Development', users_count: 2 },
  ]

  return {
    directory: page,
    teams: teamOptions,
    useAuth: vi.fn(),
    adminApi: {
      listAdminUsers: vi.fn().mockResolvedValue(page),
      listAdminTeamOptions: vi.fn().mockResolvedValue(teamOptions),
      createAdminUser: vi.fn(),
      updateAdminUser: vi.fn(),
      changeAdminUserRole: vi.fn(),
      changeAdminUserTeam: vi.fn(),
      suspendAdminUser: vi.fn(),
      restoreAdminUser: vi.fn(),
      resendAdminInvitation: vi.fn(),
      getAdminUserOwnershipImpact: vi.fn().mockResolvedValue({
        counts: { leads: 4, companies: 2, opportunities: 1, open_follow_ups: 3 },
        note: 'Suspension and team changes never reassign these records.',
      }),
    },
  }
})

vi.mock('../lib/admin', () => adminApi)
vi.mock('../lib/auth-context', () => ({ useAuth }))

const renderDirectory = (role: Role = 'admin') => {
  useAuth.mockReturnValue({
    user: { id: 1, name: 'Ada Admin', email: 'ada@primepower.test', role },
    token: 'token-1',
    login: vi.fn(),
    logout: vi.fn(),
  })

  return render(
    <MemoryRouter>
      <AdminUsers />
    </MemoryRouter>,
  )
}

describe('admin user directory', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    adminApi.listAdminUsers.mockResolvedValue(directory)
    adminApi.listAdminTeamOptions.mockResolvedValue(teams)
  })

  it('lists accounts with their role, team and status', async () => {
    renderDirectory()

    const name = await screen.findByRole('button', { name: 'Jordan Sales' })
    const row = name.closest('tr') as HTMLElement

    expect(within(row).getByText('Sales rep')).toBeInTheDocument()
    expect(within(row).getByText('Business Development')).toBeInTheDocument()
    expect(within(row).getByText('Active')).toBeInTheDocument()
    expect(within(row).getByText('jordan@primepower.test')).toBeInTheDocument()
  })

  it('filters by role, team and status', async () => {
    renderDirectory()
    await screen.findByRole('button', { name: 'Jordan Sales' })

    fireEvent.change(screen.getByLabelText('Filter by role'), { target: { value: 'manager' } })
    fireEvent.change(screen.getByLabelText('Filter by status'), {
      target: { value: 'suspended' },
    })
    fireEvent.change(screen.getByLabelText('Filter by team'), {
      target: { value: '01JTEAM0000000000000000001' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Filter' }))

    expect(adminApi.listAdminUsers).toHaveBeenLastCalledWith({
      search: '',
      role: 'manager',
      team_id: '01JTEAM0000000000000000001',
      status: 'suspended',
      page: 1,
    })
  })

  it('opens the lifecycle panel with the ownership preview and a suspend control', async () => {
    renderDirectory()
    fireEvent.click(await screen.findByRole('button', { name: 'Jordan Sales' }))

    expect(await screen.findByRole('region', { name: 'Account Jordan Sales' })).toBeInTheDocument()
    expect(await screen.findByText('Leads owned')).toBeInTheDocument()
    expect(screen.getByText('Open follow-ups')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Suspend account' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Resend invitation' })).toBeInTheDocument()
  })

  it('suspends an account with a required reason', async () => {
    adminApi.suspendAdminUser.mockResolvedValue({
      ...directory.data[0],
      status: 'suspended',
      status_reason: 'Access review.',
      can: { ...directory.data[0].can, suspend: false, restore: true },
    })

    renderDirectory()
    fireEvent.click(await screen.findByRole('button', { name: 'Jordan Sales' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Suspend account' }))

    const dialog = screen.getByRole('dialog', { name: 'Suspend account' })
    const confirm = within(dialog).getByRole('button', { name: 'Suspend' })
    expect(confirm).toBeDisabled()

    fireEvent.change(within(dialog).getByLabelText('Reason'), {
      target: { value: 'Access review.' },
    })
    fireEvent.click(confirm)

    expect(adminApi.suspendAdminUser).toHaveBeenCalledWith(
      '01JUSERREP0000000000000000',
      'Access review.',
    )
    expect(await screen.findByText('Account suspended.')).toBeInTheDocument()
  })

  it('restores a suspended account instead of offering suspend', async () => {
    adminApi.restoreAdminUser.mockResolvedValue({
      ...directory.data[1],
      status: 'active',
      status_reason: null,
      suspended_at: null,
      can: { ...directory.data[1].can, suspend: true, restore: false },
    })

    renderDirectory()
    fireEvent.click(await screen.findByRole('button', { name: 'Dana Suspended' }))

    // The reason sits inside the suspension notice beside the actor and timestamp.
    expect(await screen.findByText(/Investigating access\./)).toBeInTheDocument()
    expect(screen.getByText(/by Ada Admin/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Suspend account' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Restore account' }))
    expect(adminApi.restoreAdminUser).toHaveBeenCalledWith('01JUSERSUS0000000000000000')
    expect(await screen.findByText('Account restored.')).toBeInTheDocument()
  })

  it('offers no admin role option to an admin', async () => {
    renderDirectory('admin')
    await screen.findByRole('button', { name: 'Jordan Sales' })
    fireEvent.click(screen.getByRole('button', { name: 'New account' }))

    const createRole = screen.getByLabelText('Role')
    expect(within(createRole).queryByRole('option', { name: 'Admin' })).not.toBeInTheDocument()
    expect(within(createRole).getByRole('option', { name: 'Manager' })).toBeInTheDocument()
    expect(within(createRole).queryByRole('option', { name: 'Superadmin' })).not.toBeInTheDocument()
  })

  it('creates an account and reports the mocked invitation', async () => {
    adminApi.createAdminUser.mockResolvedValue({
      user: { ...directory.data[0], id: '01JNEW00000000000000000000', name: 'Nina Newcomer' },
      invitation: { sent: true, source: 'Email (mocked)' },
    })

    renderDirectory('superadmin')
    await screen.findByRole('button', { name: 'Jordan Sales' })
    fireEvent.click(screen.getByRole('button', { name: 'New account' }))

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Nina Newcomer' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'nina@primepower.test' } })
    fireEvent.change(screen.getByLabelText('Role'), { target: { value: 'manager' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create and invite' }))

    await waitFor(() =>
      expect(adminApi.createAdminUser).toHaveBeenCalledWith({
        name: 'Nina Newcomer',
        email: 'nina@primepower.test',
        role: 'manager',
        team_id: null,
      }),
    )
    expect(await screen.findByText(/simulated mailbox/)).toBeInTheDocument()
  })
})
