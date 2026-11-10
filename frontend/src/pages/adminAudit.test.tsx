import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AdminAudit from './admin/AdminAudit'
import type { AuditLogEntry, Paginated } from '../types'

// `vi.mock` factories hoist above the imports, so the fixtures they close over use `vi.hoisted`.
const { entries, page, auditApi, useAuth } = vi.hoisted(() => {
  const entries: AuditLogEntry[] = [
    {
      id: '01JAUDIT000000000000000001',
      action: 'user.role_changed',
      severity: 'warning',
      actor: {
        id: '01JUSERADMIN00000000000000',
        name: 'Ada Admin',
        email: 'a***@primepower.test',
      },
      subject: {
        type: 'User',
        id: '01JUSERREP0000000000000000',
        name: 'Jordan Sales',
      },
      before: { role: 'salesrep' },
      after: { role: 'manager' },
      request: {
        method: 'PATCH',
        path: 'api/v1/admin/users/01JUSERREP0000000000000000/role',
        ip: '127.0.0.1',
        user_agent: 'Vitest',
        id: '01JREQUEST0000000000000001',
      },
      created_at: '2026-11-09T02:00:00.000000Z',
    },
    {
      id: '01JAUDIT000000000000000002',
      action: 'auth.login_failed',
      severity: 'notice',
      actor: null,
      subject: null,
      before: null,
      after: { email: 'j***@primepower.test' },
      request: {
        method: 'POST',
        path: 'api/v1/auth/login',
        ip: '127.0.0.1',
        user_agent: 'Vitest',
        id: '01JREQUEST0000000000000002',
      },
      created_at: '2026-11-09T01:00:00.000000Z',
    },
  ]

  const page: Paginated<AuditLogEntry> = {
    data: entries,
    meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    links: {},
  }

  return {
    entries,
    page,
    useAuth: vi.fn(),
    auditApi: {
      listAuditLogs: vi.fn().mockResolvedValue(page),
      getAuditLog: vi.fn().mockResolvedValue(entries[0]),
      exportAuditLogs: vi.fn().mockResolvedValue(new Blob(['id,action'], { type: 'text/csv' })),
    },
  }
})

vi.mock('../lib/admin', () => auditApi)
vi.mock('../lib/auth-context', () => ({ useAuth }))

const renderAudit = () => {
  useAuth.mockReturnValue({
    user: { id: 1, name: 'Ada Admin', email: 'ada@primepower.test', role: 'admin' },
    token: 'token-1',
    login: vi.fn(),
    logout: vi.fn(),
  })

  return render(
    <MemoryRouter>
      <AdminAudit />
    </MemoryRouter>,
  )
}

describe('admin audit book', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    auditApi.listAuditLogs.mockResolvedValue(page)
    auditApi.getAuditLog.mockResolvedValue(entries[0])
    auditApi.exportAuditLogs.mockResolvedValue(new Blob(['id,action'], { type: 'text/csv' }))
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('lists events with their severity, actor and subject', async () => {
    renderAudit()

    const event = await screen.findByRole('button', { name: 'Role changed' })
    const row = event.closest('tr') as HTMLElement

    expect(within(row).getByText('user.role_changed')).toBeInTheDocument()
    expect(within(row).getByText('Warning')).toBeInTheDocument()
    expect(within(row).getByText('Ada Admin')).toBeInTheDocument()
    expect(within(row).getByText('a***@primepower.test')).toBeInTheDocument()
    expect(within(row).getByText('Jordan Sales')).toBeInTheDocument()
  })

  it('filters by event, resource, severity and correlation id', async () => {
    renderAudit()
    await screen.findByRole('button', { name: 'Role changed' })

    fireEvent.change(screen.getByLabelText('Filter by event'), {
      target: { value: 'user.role_changed' },
    })
    fireEvent.change(screen.getByLabelText('Filter by resource'), { target: { value: 'User' } })
    fireEvent.change(screen.getByLabelText('Filter by severity'), {
      target: { value: 'warning' },
    })
    fireEvent.change(screen.getByLabelText('Filter by correlation id'), {
      target: { value: '01JREQUEST0000000000000001' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Filter' }))

    expect(auditApi.listAuditLogs).toHaveBeenLastCalledWith({
      actor: '',
      action: 'user.role_changed',
      resource: 'User',
      severity: 'warning',
      from: '',
      to: '',
      request_id: '01JREQUEST0000000000000001',
      page: 1,
    })
  })

  it('opens the detail panel with the redacted snapshots and request metadata', async () => {
    renderAudit()
    fireEvent.click(await screen.findByRole('button', { name: 'Role changed' }))

    const detail = await screen.findByRole('complementary', { name: 'Audit event detail' })

    expect(within(detail).getByText('user.role_changed')).toBeInTheDocument()
    expect(within(detail).getByText(/"role": "salesrep"/)).toBeInTheDocument()
    expect(within(detail).getByText(/"role": "manager"/)).toBeInTheDocument()
    expect(within(detail).getByText(/01JREQUEST0000000000000001/)).toBeInTheDocument()
    expect(auditApi.getAuditLog).toHaveBeenCalledWith('01JAUDIT000000000000000001')
  })

  it('exports the filtered rows and reports the audited download', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const createObjectURL = vi.fn().mockReturnValue('blob:audit')
    const revokeObjectURL = vi.fn()
    Object.defineProperty(URL, 'createObjectURL', {
      value: createObjectURL,
      configurable: true,
    })
    Object.defineProperty(URL, 'revokeObjectURL', {
      value: revokeObjectURL,
      configurable: true,
    })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    renderAudit()
    await screen.findByRole('button', { name: 'Role changed' })

    fireEvent.change(screen.getByLabelText('Filter by severity'), {
      target: { value: 'warning' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }))

    await waitFor(() => {
      expect(auditApi.exportAuditLogs).toHaveBeenCalledWith({
        actor: '',
        action: '',
        resource: '',
        severity: 'warning',
        from: '',
        to: '',
        request_id: '',
      })
    })
    expect(createObjectURL).toHaveBeenCalledOnce()
    expect(click).toHaveBeenCalledOnce()
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:audit')
    expect(
      await screen.findByText('The export was generated and recorded in the audit trail.'),
    ).toBeInTheDocument()
  })

  it('surfaces the export bound as a validation error', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    auditApi.exportAuditLogs.mockRejectedValue({
      response: {
        status: 422,
        data: {
          message: 'The export is limited to 5000 rows.',
          errors: {
            export: ['The export is limited to 5000 rows. Narrow the filters and try again.'],
          },
        },
      },
    })

    renderAudit()
    await screen.findByRole('button', { name: 'Role changed' })
    fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }))

    expect(
      await screen.findByText(
        'The export is limited to 5000 rows. Narrow the filters and try again.',
      ),
    ).toBeInTheDocument()
  })
})
