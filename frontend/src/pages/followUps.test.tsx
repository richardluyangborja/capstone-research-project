import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FollowUpForm from './FollowUpForm'
import FollowUps from './FollowUps'
import * as followUpApi from '../lib/followUps'
import * as communicationApi from '../lib/communications'
import type { FollowUp, FollowUpMessage, FollowUpsPage, Task } from '../types'

// `vi.mock` factories are hoisted above the imports, so fixtures must come from `vi.hoisted`.
const { overdueTask, page, messagePage, task, session } = vi.hoisted(() => {
  const base = {
    subject: 'Send the revised rate sheet',
    body: 'Cover the two supervisor roles we discussed.',
    record: { type: 'company' as const, id: 'company-1', label: 'Sunrise Foods Inc' },
    owner: { id: 2, name: 'Sam Salesrep' },
    owner_id: 2,
    due_at: '2026-10-17T07:00:00.000000Z',
    due: { state: 'overdue' as const, label: 'Overdue', days: -2, overdue: true },
    status: { value: 'open' as const, label: 'Open' },
    channel: null,
    recipient: null,
    can_update: true,
    can_transition: true,
    can_send: false,
    can_delete: false,
    created_at: '2026-10-15T07:00:00.000000Z',
    updated_at: '2026-10-15T07:00:00.000000Z',
  }

  const overdueTask: FollowUp = { kind: 'task', id: 'task-1', ...base }

  const todayTask: FollowUp = {
    kind: 'task',
    id: 'task-2',
    ...base,
    subject: 'Confirm the 40-seat start date',
    body: 'Carla needs the ramp-up week in writing.',
    record: {
      type: 'opportunity',
      id: 'deal-1',
      label: 'BPO Solutions PH — 40-seat contact centre ramp',
    },
    owner: { id: 3, name: 'Liza Lopez' },
    owner_id: 3,
    due_at: '2026-10-19T09:00:00.000000Z',
    due: { state: 'today', label: 'Due today', days: 0, overdue: false },
  }

  const page: FollowUpsPage = {
    data: [overdueTask, todayTask],
    meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    counts: { overdue: 3, today: 1, upcoming: 4, open: 8, done: 2 },
  }

  // Slice S2: a scheduled message joins the same worklist, read as the second shape.
  const scheduledMessage: FollowUpMessage = {
    kind: 'message',
    id: 'com-9',
    reference: 'COM-2026-0014',
    subject: 'Rate sheet follow-up for the night shift',
    body: 'Just checking the rates reach you before Friday.',
    record: { type: 'company', id: 'company-1', label: 'Sunrise Foods Inc' },
    owner: { id: 2, name: 'Sam Salesrep' },
    owner_id: 2,
    due_at: '2026-10-20T01:00:00.000000Z',
    due: { state: 'upcoming', label: 'Upcoming', days: 1, overdue: false },
    status: { value: 'scheduled', label: 'Scheduled' },
    channel: 'email',
    recipient: 'Rosa Dela Cruz <rosa@sunrise.test>',
    to_name: 'Rosa Dela Cruz',
    to_address: 'rosa@sunrise.test',
    can_update: true,
    can_transition: false,
    can_send: true,
    can_delete: true,
    created_at: '2026-10-18T01:00:00.000000Z',
    updated_at: '2026-10-18T01:00:00.000000Z',
  }

  const messagePage: FollowUpsPage = {
    data: [scheduledMessage],
    meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    counts: { overdue: 3, today: 1, upcoming: 5, open: 9, done: 2 },
  }

  const task: Task = { id: 'task-1', ...base }

  // Mutated by a test that needs a salesrep's narrower rights (ADR-0004), or an admin's dispatch.
  const session = { role: 'manager' as 'manager' | 'salesrep' | 'admin' }

  return { overdueTask, scheduledMessage, page, messagePage, task, session }
})

vi.mock('../lib/followUps', () => ({
  listFollowUps: vi.fn(),
  getTask: vi.fn(),
  createTask: vi.fn(),
  updateTask: vi.fn(),
  updateTaskStatus: vi.fn(),
}))

// The worklist's message actions are the history book's own calls (slice S2).
vi.mock('../lib/communications', () => ({
  sendCommunication: vi.fn(),
  deleteCommunication: vi.fn(),
  dispatchDueCommunications: vi.fn(),
}))

vi.mock('../lib/auth-context', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Maria Manager', role: session.role },
    logout: vi.fn(),
  }),
}))

vi.mock('../lib/leads', () => ({
  getAssignableUsers: vi.fn().mockResolvedValue([
    { id: 2, name: 'Sam Salesrep', team_id: 'team-1' },
    { id: 3, name: 'Liza Lopez', team_id: 'team-1' },
  ]),
  parseValidationError: vi.fn().mockReturnValue(null),
  getLead: vi.fn(),
  listLeads: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }),
}))

vi.mock('../lib/companies', () => ({
  getCompany: vi.fn().mockResolvedValue({ id: 'company-1', name: 'Sunrise Foods Inc' }),
  listCompanies: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }),
}))

vi.mock('../lib/contacts', () => ({
  getContact: vi.fn(),
  listContacts: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }),
}))

vi.mock('../lib/opportunities', () => ({
  getOpportunity: vi.fn(),
  listOpportunities: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }),
}))

beforeEach(() => {
  vi.clearAllMocks()
  session.role = 'manager'
  vi.mocked(followUpApi.listFollowUps).mockResolvedValue(page)
  vi.mocked(followUpApi.getTask).mockResolvedValue(task)
  vi.mocked(followUpApi.createTask).mockResolvedValue(task)
  vi.mocked(followUpApi.updateTask).mockResolvedValue(task)
  vi.mocked(followUpApi.updateTaskStatus).mockResolvedValue(task)
  vi.mocked(communicationApi.dispatchDueCommunications).mockResolvedValue({
    attempted: 2,
    sent: 1,
    failed: 1,
    source: 'Email (mocked)',
  })
})

const renderWorklist = () =>
  render(
    <MemoryRouter initialEntries={['/follow-ups']}>
      <Routes>
        <Route path="/follow-ups" element={<FollowUps />} />
      </Routes>
    </MemoryRouter>,
  )

const renderForm = (entry: string) =>
  render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path="/follow-ups/new" element={<FollowUpForm />} />
        <Route path="/follow-ups/:id" element={<FollowUpForm />} />
        <Route path="/follow-ups" element={<div>Worklist</div>} />
      </Routes>
    </MemoryRouter>,
  )

describe('the follow-up worklist', () => {
  it('lists what is owed with its due state, record and counts', async () => {
    renderWorklist()

    expect(await screen.findByText('Send the revised rate sheet')).toBeInTheDocument()
    expect(screen.getByText('Confirm the 40-seat start date')).toBeInTheDocument()
    // The due state and the kind come from the API's derived blocks.
    const table = screen.getByRole('table')
    expect(within(table).getAllByText('Overdue').length).toBeGreaterThan(0)
    expect(within(table).getByText('Due today')).toBeInTheDocument()
    expect(within(table).getAllByText('Task').length).toBe(2)
    // The row links to the follow-up and to the record it hangs off.
    expect(screen.getByRole('link', { name: 'Send the revised rate sheet' })).toHaveAttribute(
      'href',
      '/follow-ups/task-1',
    )
    expect(screen.getByRole('link', { name: 'Sunrise Foods Inc' })).toHaveAttribute(
      'href',
      '/companies/company-1',
    )
    // The header counts the work, and a completed task is only counted, not listed.
    expect(screen.getByText('Completed')).toBeInTheDocument()
    expect(screen.getByText('4')).toBeInTheDocument()
  })

  it('narrows by the owner tab and by state', async () => {
    renderWorklist()
    await screen.findByText('Send the revised rate sheet')

    fireEvent.click(screen.getByRole('button', { name: 'Mine' }))

    await waitFor(() =>
      expect(followUpApi.listFollowUps).toHaveBeenLastCalledWith(
        expect.objectContaining({ owner: 'me' }),
      ),
    )

    fireEvent.change(screen.getByLabelText('State filter'), { target: { value: 'done' } })

    await waitFor(() =>
      expect(followUpApi.listFollowUps).toHaveBeenLastCalledWith(
        expect.objectContaining({ state: 'done' }),
      ),
    )
  })

  it('completes a task from the row and re-reads the worklist', async () => {
    renderWorklist()
    await screen.findByText('Send the revised rate sheet')
    expect(followUpApi.listFollowUps).toHaveBeenCalledTimes(1)

    fireEvent.click(screen.getAllByRole('button', { name: 'Complete' })[0])

    await waitFor(() => expect(followUpApi.updateTaskStatus).toHaveBeenCalledWith('task-1', 'done'))
    await waitFor(() => expect(followUpApi.listFollowUps).toHaveBeenCalledTimes(2))
  })

  it('cancels a task from the row', async () => {
    renderWorklist()
    await screen.findByText('Send the revised rate sheet')

    fireEvent.click(screen.getAllByRole('button', { name: 'Cancel' })[0])

    await waitFor(() =>
      expect(followUpApi.updateTaskStatus).toHaveBeenCalledWith('task-1', 'cancelled'),
    )
  })

  it('reopens a finished task and offers no complete on it', async () => {
    vi.mocked(followUpApi.listFollowUps).mockResolvedValue({
      ...page,
      data: [
        {
          ...overdueTask,
          status: { value: 'cancelled', label: 'Cancelled' },
        },
      ],
    })

    renderWorklist()
    await screen.findByText('Send the revised rate sheet')

    expect(screen.queryByRole('button', { name: 'Complete' })).not.toBeInTheDocument()
    expect(within(screen.getByRole('table')).getByText('Cancelled')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Reopen' }))

    await waitFor(() => expect(followUpApi.updateTaskStatus).toHaveBeenCalledWith('task-1', 'open'))
  })

  it('reschedules a task through the dialog', async () => {
    renderWorklist()
    await screen.findByText('Send the revised rate sheet')

    fireEvent.click(screen.getAllByRole('button', { name: 'Reschedule' })[0])

    const dialog = screen.getByRole('dialog', { name: 'Reschedule follow-up' })
    fireEvent.change(within(dialog).getByLabelText('New due moment'), {
      target: { value: '2026-11-01T09:00' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Reschedule' }))

    await waitFor(() =>
      expect(followUpApi.updateTask).toHaveBeenCalledWith('task-1', {
        due_at: new Date('2026-11-01T09:00').toISOString(),
      }),
    )
  })

  it('surfaces a refused move instead of silently failing', async () => {
    vi.mocked(followUpApi.updateTaskStatus).mockRejectedValue(new Error('nope'))
    const { parseValidationError } = await import('../lib/leads')
    vi.mocked(parseValidationError).mockReturnValueOnce({
      message: 'A done follow-up cannot move to done.',
      errors: {},
    })

    renderWorklist()
    await screen.findByText('Send the revised rate sheet')

    fireEvent.click(screen.getAllByRole('button', { name: 'Complete' })[0])

    expect(await screen.findByText('A done follow-up cannot move to done.')).toBeInTheDocument()
  })

  it('lists a scheduled message as the second shape, with what only a message has', async () => {
    vi.mocked(followUpApi.listFollowUps).mockResolvedValue(messagePage)

    renderWorklist()

    expect(await screen.findByText('Rate sheet follow-up for the night shift')).toBeInTheDocument()
    const table = screen.getByRole('table')
    // The kind, the state and the recipient are the message's own.
    expect(within(table).getByText('Email')).toBeInTheDocument()
    expect(within(table).getByText('Scheduled')).toBeInTheDocument()
    expect(within(table).getByText('To Rosa Dela Cruz <rosa@sunrise.test>')).toBeInTheDocument()
    // It opens in the history book, not in the task form, and it is sent rather than completed.
    expect(
      screen.getByRole('link', { name: 'Rate sheet follow-up for the night shift' }),
    ).toHaveAttribute('href', '/communications/com-9')
    expect(within(table).getByRole('button', { name: 'Send now' })).toBeInTheDocument()
    expect(within(table).queryByRole('button', { name: 'Complete' })).not.toBeInTheDocument()
  })

  it('sends a scheduled message from the row, and re-reads the worklist', async () => {
    vi.mocked(followUpApi.listFollowUps).mockResolvedValue(messagePage)

    renderWorklist()
    await screen.findByText('Rate sheet follow-up for the night shift')

    fireEvent.click(screen.getByRole('button', { name: 'Send now' }))

    await waitFor(() => expect(communicationApi.sendCommunication).toHaveBeenCalledWith('com-9'))
    await waitFor(() => expect(followUpApi.listFollowUps).toHaveBeenCalledTimes(2))
  })

  it('cancels a scheduled message with the book\u2019s soft delete', async () => {
    vi.mocked(followUpApi.listFollowUps).mockResolvedValue(messagePage)

    renderWorklist()
    await screen.findByText('Rate sheet follow-up for the night shift')

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    await waitFor(() => expect(communicationApi.deleteCommunication).toHaveBeenCalledWith('com-9'))
  })

  it('narrows the worklist to one shape at a time', async () => {
    renderWorklist()
    await screen.findByText('Send the revised rate sheet')

    fireEvent.change(screen.getByLabelText('Kind filter'), { target: { value: 'message' } })

    await waitFor(() =>
      expect(followUpApi.listFollowUps).toHaveBeenLastCalledWith(
        expect.objectContaining({ kind: 'message' }),
      ),
    )
  })

  it('lets an admin dispatch what has fallen due, and reports it', async () => {
    session.role = 'admin'
    renderWorklist()
    await screen.findByText('Send the revised rate sheet')

    fireEvent.click(screen.getByRole('button', { name: 'Dispatch due sends' }))

    await waitFor(() => expect(communicationApi.dispatchDueCommunications).toHaveBeenCalledTimes(1))
    expect(
      await screen.findByText('1 of 2 due messages sent through the simulated mailbox, 1 failed.'),
    ).toBeInTheDocument()
  })

  it('offers a manager no dispatch: one run writes across every team', async () => {
    renderWorklist()
    await screen.findByText('Send the revised rate sheet')

    expect(screen.queryByRole('button', { name: 'Dispatch due sends' })).not.toBeInTheDocument()
  })
})

describe('the follow-up form', () => {
  it('creates a task on the record a deep link names', async () => {
    renderForm('/follow-ups/new?subject_type=company&subject_id=company-1')

    // The deep link answers the record picker, and the label is looked up.
    expect(await screen.findByText('Sunrise Foods Inc')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Follow-up'), {
      target: { value: 'Send the night-shift rate card' },
    })
    fireEvent.change(screen.getByLabelText('Due'), { target: { value: '2026-11-02T09:00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create follow-up' }))

    await waitFor(() =>
      expect(followUpApi.createTask).toHaveBeenCalledWith({
        subject_type: 'company',
        subject_id: 'company-1',
        subject: 'Send the night-shift rate card',
        body: null,
        due_at: new Date('2026-11-02T09:00').toISOString(),
      }),
    )
    // It lands on the task it just created, read back on its own route.
    await waitFor(() => expect(followUpApi.getTask).toHaveBeenCalledWith('task-1'))
  })

  it('hands the task to a team member when the caller may', async () => {
    renderForm('/follow-ups/new?subject_type=company&subject_id=company-1')
    await screen.findByText('Sunrise Foods Inc')

    fireEvent.change(screen.getByLabelText('Follow-up'), { target: { value: 'Call them back' } })
    fireEvent.change(screen.getByLabelText('Owes it'), { target: { value: '3' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create follow-up' }))

    await waitFor(() =>
      expect(followUpApi.createTask).toHaveBeenCalledWith(
        expect.objectContaining({ subject: 'Call them back', owner_id: 3 }),
      ),
    )
  })

  it('edits an existing task and moves it through its states', async () => {
    renderForm('/follow-ups/task-1')

    const subject = await screen.findByLabelText('Follow-up')
    expect(subject).toHaveValue('Send the revised rate sheet')
    expect(screen.getByLabelText('Notes')).toHaveValue(
      'Cover the two supervisor roles we discussed.',
    )
    // The record is shown, not re-pointed.
    expect(screen.getByText('Sunrise Foods Inc')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Complete' }))

    await waitFor(() => expect(followUpApi.updateTaskStatus).toHaveBeenCalledWith('task-1', 'done'))
    expect(await screen.findByText('Moved to done.')).toBeInTheDocument()

    fireEvent.change(subject, { target: { value: 'Send the corrected rate sheet' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() =>
      expect(followUpApi.updateTask).toHaveBeenCalledWith(
        'task-1',
        expect.objectContaining({ subject: 'Send the corrected rate sheet' }),
      ),
    )
  })

  it('gives a salesrep no way to hand the work to anyone else', async () => {
    session.role = 'salesrep'
    renderForm('/follow-ups/new?subject_type=company&subject_id=company-1')
    await screen.findByText('Sunrise Foods Inc')

    // The record picker is answered by the deep link; the assignee can only be the caller.
    expect(screen.getByLabelText('Owes it')).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Follow-up'), { target: { value: 'Call them back' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create follow-up' }))

    await waitFor(() => expect(followUpApi.createTask).toHaveBeenCalled())
    expect(vi.mocked(followUpApi.createTask).mock.calls[0][0]).not.toHaveProperty('owner_id')
  })

  it('asks for the record before it can create anything', async () => {
    renderForm('/follow-ups/new')

    fireEvent.change(screen.getByLabelText('Follow-up'), { target: { value: 'Nothing to hang' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create follow-up' }))

    expect(
      await screen.findByText('Choose the record this follow-up belongs to.'),
    ).toBeInTheDocument()
    expect(followUpApi.createTask).not.toHaveBeenCalled()
  })
})
