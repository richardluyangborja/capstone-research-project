import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CommunicationTemplates from './CommunicationTemplates'
import CommunicationTemplateForm from './CommunicationTemplateForm'
import NewCommunication from './NewCommunication'
import * as templateApi from '../lib/communicationTemplates'
import * as leadApi from '../lib/leads'
import type { CommunicationTemplate } from '../types'

// `vi.mock` factories are hoisted above the imports, so fixtures and the mutable session the
// auth mock reads must come from `vi.hoisted`.
const { auth, rateSheet, retired, book, repBook, mergeFields } = vi.hoisted(() => {
  const auth = { role: 'manager' as 'manager' | 'salesrep' }

  const base = {
    channel: 'email' as const,
    created_by: 1,
    author: { id: 1, name: 'Maria Manager' },
    can_update: true,
    can_delete: true,
    can_restore: true,
    deleted_at: null,
    created_at: '2026-10-18T02:00:00.000000Z',
    updated_at: '2026-10-18T02:00:00.000000Z',
  }

  const rateSheet: CommunicationTemplate = {
    ...base,
    id: 'tpl-1',
    name: 'Rate sheet follow-up',
    is_active: true,
    subject: 'Our updated rate sheet for {{company.name}}',
    body: 'Hi {{contact.first_name}},\n\nHere is the rate sheet for {{company.name}}.',
  }

  const callScript: CommunicationTemplate = {
    ...base,
    id: 'tpl-2',
    name: 'Cold call script',
    channel: 'call',
    is_active: true,
    subject: 'First call — {{company.name}}',
    body: 'Good morning, this is {{user.name}}.',
  }

  // Kept, but no longer offered when writing a message.
  const greeting: CommunicationTemplate = {
    ...base,
    id: 'tpl-3',
    name: 'Holiday greeting',
    is_active: false,
    subject: "Season's greetings",
    body: 'Hi {{contact.first_name}}.',
  }

  // Soft-deleted: out of the book, and the API serves nothing by id — only Restore.
  const retired: CommunicationTemplate = {
    ...base,
    id: 'tpl-4',
    name: 'Retired wording',
    is_active: true,
    can_update: false,
    can_delete: false,
    deleted_at: '2026-10-18T03:00:00.000000Z',
    subject: 'Old wording',
    body: 'Old body.',
  }

  const book = {
    data: [rateSheet, callScript, greeting, retired],
    meta: { current_page: 1, per_page: 25, total: 4, last_page: 1 },
  }

  // What the API sends a salesrep: the same rows, readable but not theirs to author or retire
  // (the `can_*` flags are the policy's answer, so the book does not re-decide them).
  const repBook = {
    data: [rateSheet, callScript, greeting].map((template) => ({
      ...template,
      can_update: false,
      can_delete: false,
      can_restore: false,
    })),
    meta: { current_page: 1, per_page: 25, total: 3, last_page: 1 },
  }

  const mergeFields = [
    { token: 'contact.first_name', label: 'Contact first name' },
    { token: 'company.name', label: 'Account name' },
    { token: 'user.name', label: 'Your name' },
  ]

  return { auth, rateSheet, callScript, greeting, retired, book, repBook, mergeFields }
})

vi.mock('../lib/auth-context', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Maria Manager', role: auth.role }, logout: vi.fn() }),
}))

vi.mock('../lib/communicationTemplates', () => ({
  listCommunicationTemplates: vi.fn(),
  getCommunicationTemplate: vi.fn(),
  createCommunicationTemplate: vi.fn(),
  updateCommunicationTemplate: vi.fn(),
  deleteCommunicationTemplate: vi.fn(),
  restoreCommunicationTemplate: vi.fn(),
  listCommunicationMergeFields: vi.fn(),
  renderCommunicationTemplate: vi.fn(),
}))

vi.mock('../lib/communications', () => ({
  createCommunication: vi.fn(),
  getCommunication: vi.fn(),
  replyToCommunication: vi.fn(),
}))

vi.mock('../lib/leads', () => ({
  getLead: vi.fn(),
  listLeads: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }),
  parseValidationError: vi.fn().mockReturnValue(null),
}))

vi.mock('../lib/companies', () => ({
  getCompany: vi.fn().mockResolvedValue({ id: 'co-1', name: 'Sunrise Foods Inc' }),
  listCompanies: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }),
}))

vi.mock('../lib/contacts', () => ({
  getContact: vi.fn(),
  listContacts: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }),
  listCompanyContacts: vi.fn().mockResolvedValue([]),
}))

vi.mock('../lib/opportunities', () => ({
  getOpportunity: vi.fn(),
  listOpportunities: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }),
}))

beforeEach(() => {
  auth.role = 'manager'
  vi.mocked(templateApi.listCommunicationTemplates).mockResolvedValue(book)
  vi.mocked(templateApi.getCommunicationTemplate).mockResolvedValue(rateSheet)
  vi.mocked(templateApi.listCommunicationMergeFields).mockResolvedValue(mergeFields)
  vi.mocked(templateApi.deleteCommunicationTemplate).mockResolvedValue(undefined)
  vi.mocked(templateApi.restoreCommunicationTemplate).mockResolvedValue(retired)
})

const renderBook = () =>
  render(
    <MemoryRouter>
      <CommunicationTemplates />
    </MemoryRouter>,
  )

describe('Template book', () => {
  it('lists the wording with its channel, state and author', async () => {
    renderBook()

    const row = (await screen.findByRole('link', { name: 'Rate sheet follow-up' })).closest(
      'tr',
    ) as HTMLElement

    // A live template is what the composer picks from, and it can be edited or retired.
    expect(within(row).getByText('Email')).toBeInTheDocument()
    expect(within(row).getByText('Active')).toBeInTheDocument()
    expect(within(row).getByText('Maria Manager')).toBeInTheDocument()
    expect(within(row).getByRole('button', { name: 'Retire' })).toBeInTheDocument()

    const inactive = screen.getByText('Holiday greeting').closest('tr') as HTMLElement
    expect(within(inactive).getByText('Inactive')).toBeInTheDocument()

    // A retired one is out of the picker and cannot be opened — only brought back.
    const gone = screen.getByText('Retired wording').closest('tr') as HTMLElement
    expect(within(gone).getByText('Retired')).toBeInTheDocument()
    expect(within(gone).queryByRole('link')).toBeNull()
    expect(within(gone).getByRole('button', { name: 'Restore' })).toBeInTheDocument()
  })

  it('offers authoring to a manager but not to a salesrep', async () => {
    auth.role = 'salesrep'
    vi.mocked(templateApi.listCommunicationTemplates).mockResolvedValue(repBook)
    renderBook()

    await screen.findByText('Rate sheet follow-up')

    // Reading and using are open to everyone; writing the house's wording is not.
    expect(screen.queryByRole('link', { name: 'New template' })).toBeNull()
    const row = screen.getByText('Rate sheet follow-up').closest('tr') as HTMLElement
    expect(within(row).queryByRole('button', { name: 'Retire' })).toBeNull()
  })

  it('asks for the state the filter names', async () => {
    renderBook()

    await screen.findByText('Rate sheet follow-up')
    fireEvent.change(screen.getByLabelText('State filter'), { target: { value: 'retired' } })

    // Retired rows are soft-deleted ones, so the filter travels as its own knob.
    await waitFor(() =>
      expect(templateApi.listCommunicationTemplates).toHaveBeenLastCalledWith(
        expect.objectContaining({ state: 'retired' }),
      ),
    )
  })

  it('retires and restores from the row', async () => {
    renderBook()

    const live = (await screen.findByText('Rate sheet follow-up')).closest('tr') as HTMLElement
    fireEvent.click(within(live).getByRole('button', { name: 'Retire' }))

    await waitFor(() =>
      expect(templateApi.deleteCommunicationTemplate).toHaveBeenCalledWith('tpl-1'),
    )
    expect(await screen.findByText(/retired — it no longer appears/)).toBeInTheDocument()

    const gone = screen.getByText('Retired wording').closest('tr') as HTMLElement
    fireEvent.click(within(gone).getByRole('button', { name: 'Restore' }))

    await waitFor(() =>
      expect(templateApi.restoreCommunicationTemplate).toHaveBeenCalledWith('tpl-4'),
    )
  })
})

describe('Writing a template', () => {
  const renderForm = (path = '/communications/templates/new') =>
    render(
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/communications/templates/new" element={<CommunicationTemplateForm />} />
          <Route path="/communications/templates/:id" element={<CommunicationTemplateForm />} />
        </Routes>
      </MemoryRouter>,
    )

  it('drops a merge field into the field that was last in focus and saves', async () => {
    renderForm()

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Site visit note' } })
    fireEvent.change(screen.getByLabelText('Title line'), {
      target: { value: 'About {{company.name}}' },
    })

    const body = screen.getByLabelText('Body') as HTMLTextAreaElement
    fireEvent.focus(body)
    fireEvent.change(body, { target: { value: 'Hi ' } })

    // The chips are the vocabulary the API serves, so the two cannot drift apart.
    fireEvent.click(await screen.findByRole('button', { name: '{{contact.first_name}}' }))
    expect(body.value).toBe('Hi {{contact.first_name}}')

    fireEvent.click(screen.getByRole('button', { name: 'Create template' }))

    await waitFor(() =>
      expect(templateApi.createCommunicationTemplate).toHaveBeenCalledWith({
        name: 'Site visit note',
        channel: 'email',
        subject: 'About {{company.name}}',
        body: 'Hi {{contact.first_name}}',
        is_active: true,
      }),
    )
  })

  it('loads an existing template and saves the edit', async () => {
    renderForm('/communications/templates/tpl-1')

    expect(await screen.findByLabelText('Name')).toHaveValue('Rate sheet follow-up')

    fireEvent.change(screen.getByLabelText('Name'), {
      target: { value: 'Rate sheet follow-up (v2)' },
    })
    fireEvent.click(screen.getByRole('checkbox', { name: 'Offered in the composer' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save template' }))

    await waitFor(() =>
      expect(templateApi.updateCommunicationTemplate).toHaveBeenCalledWith(
        'tpl-1',
        expect.objectContaining({ name: 'Rate sheet follow-up (v2)', is_active: false }),
      ),
    )
  })

  it('shows why the API refused a name', async () => {
    vi.mocked(templateApi.createCommunicationTemplate).mockRejectedValueOnce(
      new Error('422 Unprocessable Entity'),
    )
    vi.mocked(leadApi.parseValidationError).mockReturnValueOnce({
      message: 'The given data was invalid.',
      errors: { name: ['Another template already uses that name.'] },
    })

    renderForm()
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Rate sheet follow-up' } })
    fireEvent.change(screen.getByLabelText('Title line'), { target: { value: 'Rates' } })
    fireEvent.change(screen.getByLabelText('Body'), { target: { value: 'Body' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create template' }))

    // The name is refused, and the form says so rather than pretending it saved.
    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Another template already uses that name.')
  })
})

describe('Starting a message from a template', () => {
  it('fills the channel, the title line and the body from the record', async () => {
    vi.mocked(templateApi.renderCommunicationTemplate).mockResolvedValue({
      template_id: 'tpl-1',
      channel: 'email',
      subject: 'Our updated rate sheet for Sunrise Foods Inc',
      body: 'Hi Ramon,\n\nHere is the rate sheet for Sunrise Foods Inc.',
      unresolved: [],
    })

    render(
      <MemoryRouter
        initialEntries={[
          '/communications/new?subject_type=company&subject_id=co-1&subject_label=Sunrise%20Foods%20Inc',
        ]}
      >
        <Routes>
          <Route path="/communications/new" element={<NewCommunication />} />
        </Routes>
      </MemoryRouter>,
    )

    // Only the active templates are asked for, so an inactive one never reaches the picker.
    const picker = await screen.findByLabelText('Template')
    expect(templateApi.listCommunicationTemplates).toHaveBeenCalledWith({ state: 'active' })

    fireEvent.change(picker, { target: { value: 'tpl-1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Use template' }))

    await waitFor(() =>
      expect(templateApi.renderCommunicationTemplate).toHaveBeenCalledWith('tpl-1', {
        subject_type: 'company',
        subject_id: 'co-1',
        contact_id: null,
      }),
    )

    expect(screen.getByLabelText('Subject')).toHaveValue(
      'Our updated rate sheet for Sunrise Foods Inc',
    )
    expect(screen.getByLabelText('Body')).toHaveValue(
      'Hi Ramon,\n\nHere is the rate sheet for Sunrise Foods Inc.',
    )
    expect(await screen.findByText(/Filled from the record/)).toBeInTheDocument()
  })

  it('names what the template could not fill for this record', async () => {
    vi.mocked(templateApi.renderCommunicationTemplate).mockResolvedValue({
      template_id: 'tpl-1',
      channel: 'email',
      subject: 'Greetings',
      body: 'Hi {{contact.position}}, welcome.',
      unresolved: ['contact.position'],
    })

    render(
      <MemoryRouter
        initialEntries={[
          '/communications/new?subject_type=company&subject_id=co-1&subject_label=Sunrise',
        ]}
      >
        <Routes>
          <Route path="/communications/new" element={<NewCommunication />} />
        </Routes>
      </MemoryRouter>,
    )

    fireEvent.change(await screen.findByLabelText('Template'), { target: { value: 'tpl-1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Use template' }))

    // The gap is named rather than silently blanked, and the placeholder is still in the text.
    expect(await screen.findByText(/Could not fill \{\{contact.position\}\}/)).toBeInTheDocument()
    expect(screen.getByLabelText('Body')).toHaveValue('Hi {{contact.position}}, welcome.')
  })
})
