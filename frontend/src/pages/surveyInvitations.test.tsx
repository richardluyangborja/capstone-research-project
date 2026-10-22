import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SurveyInvitations from './SurveyInvitations'
import CompanySatisfactionPanel from '../components/common/CompanySatisfactionPanel'
import type { CompanySatisfaction, Survey, SurveyInvitation } from '../types'

// Module 05 slice S2 — the invitation book and the account's Satisfaction tab. Both read the same
// rows; the book is the cross-account view, the tab is the account's own.

const {
  listSurveyInvitations,
  listSurveys,
  cancelSurveyInvitation,
  resendSurveyInvitation,
  listCompanySurveyInvitations,
  sendSurveyInvitation,
  listCompanyContacts,
  listCompanyContracts,
  getCompanySatisfaction,
  card,
  openInvitation,
  answeredInvitation,
  activeSurvey,
} = vi.hoisted(() => {
  const base = {
    survey: { id: 'survey-1', name: 'Post-deployment check-in', description: null },
    company: { id: 'company-1', name: 'Harbor Logistics' },
    contact: null,
    contract: null,
    recipient_name: 'Grace Lim',
    recipient_email: 'grace@harbor.test',
    message: null,
    failed_reason: null,
    sent_at: '2026-10-18T02:00:00.000000Z',
    expires_at: '2026-11-01T02:00:00.000000Z',
    submitted_at: null,
    communication: null,
    response: null,
    can_cancel: true,
    can_resend: true,
    created_at: '2026-10-18T02:00:00.000000Z',
    updated_at: '2026-10-18T02:00:00.000000Z',
  }

  const openInvitation: SurveyInvitation = {
    ...base,
    id: 'invitation-1',
    reference: 'SRV-2026-0001',
    status: 'sent',
    state: 'sent',
    state_label: 'Awaiting a reply',
    public_url: 'http://localhost:5173/survey/token-abc',
  }

  const answeredInvitation: SurveyInvitation = {
    ...base,
    id: 'invitation-2',
    reference: 'SRV-2026-0002',
    status: 'submitted',
    state: 'submitted',
    state_label: 'Submitted',
    public_url: undefined,
    submitted_at: '2026-10-19T02:00:00.000000Z',
    can_cancel: false,
    can_resend: false,
    response: {
      id: 'response-1',
      submitted_at: '2026-10-19T02:00:00.000000Z',
      csat: 5,
      nps: 10,
      band: 'promoter',
      needs_attention: false,
      resolved: false,
      resolved_at: null,
      resolved_by: null,
      resolution_note: null,
      can_resolve: false,
      created_at: '2026-10-19T02:00:00.000000Z',
    },
  }

  const activeSurvey: Survey = {
    id: 'survey-1',
    name: 'Post-deployment check-in',
    description: null,
    is_active: true,
    questions_count: 3,
    invitations_count: 2,
    questions_editable: false,
    created_by: 1,
    author: { id: 1, name: 'Maria Manager' },
    can_update: true,
    can_delete: true,
    can_restore: true,
    can_duplicate: true,
    deleted_at: null,
    created_at: '2026-10-19T02:00:00.000000Z',
    updated_at: '2026-10-20T02:00:00.000000Z',
  }

  const card: CompanySatisfaction = {
    responses: 1,
    csat: 5,
    nps: 10,
    promoters: 1,
    passives: 0,
    detractors: 0,
    answered: 1,
    attention: 0,
    invitations: 2,
    response_rate: 50,
    latest: {
      id: 'response-1',
      survey: { id: 'survey-1', name: 'Post-deployment check-in' },
      csat: 5,
      nps: 10,
      band: 'promoter',
      band_label: 'Promoter',
      needs_attention: false,
      submitted_at: '2026-10-19T02:00:00.000000Z',
    },
    trend: [{ submitted_at: '2026-10-19T02:00:00.000000Z', csat: 5, nps: 10 }],
    recent: [],
  }

  return {
    listSurveyInvitations: vi.fn(),
    listSurveys: vi.fn(),
    cancelSurveyInvitation: vi.fn(),
    resendSurveyInvitation: vi.fn(),
    listCompanySurveyInvitations: vi.fn(),
    sendSurveyInvitation: vi.fn(),
    listCompanyContacts: vi.fn(),
    listCompanyContracts: vi.fn(),
    getCompanySatisfaction: vi.fn(),
    card,
    openInvitation,
    answeredInvitation,
    activeSurvey,
  }
})

vi.mock('../lib/surveys', () => ({
  listSurveyInvitations: (...args: unknown[]) => listSurveyInvitations(...args),
  listSurveys: (...args: unknown[]) => listSurveys(...args),
  cancelSurveyInvitation: (...args: unknown[]) => cancelSurveyInvitation(...args),
  resendSurveyInvitation: (...args: unknown[]) => resendSurveyInvitation(...args),
  listCompanySurveyInvitations: (...args: unknown[]) => listCompanySurveyInvitations(...args),
  sendSurveyInvitation: (...args: unknown[]) => sendSurveyInvitation(...args),
}))

vi.mock('../lib/contacts', () => ({
  listCompanyContacts: (...args: unknown[]) => listCompanyContacts(...args),
}))

vi.mock('../lib/contracts', () => ({
  listCompanyContracts: (...args: unknown[]) => listCompanyContracts(...args),
}))

vi.mock('../lib/satisfaction', () => ({
  getCompanySatisfaction: (...args: unknown[]) => getCompanySatisfaction(...args),
}))

const page = (rows: SurveyInvitation[]) => ({
  data: rows,
  meta: { current_page: 1, last_page: 1, total: rows.length, per_page: 25 },
})

describe('the invitation book', () => {
  beforeEach(() => {
    listSurveyInvitations.mockReset()
    listSurveys.mockReset()
    cancelSurveyInvitation.mockReset()
    resendSurveyInvitation.mockReset()

    listSurveyInvitations.mockResolvedValue(page([openInvitation, answeredInvitation]))
    listSurveys.mockResolvedValue({
      data: [activeSurvey],
      meta: { current_page: 1, last_page: 1, total: 1 },
    })
  })

  const renderBook = () =>
    render(
      <MemoryRouter>
        <SurveyInvitations />
      </MemoryRouter>,
    )

  it('lists every send with its account, recipient and derived state', async () => {
    renderBook()

    expect(await screen.findByText('SRV-2026-0001')).toBeInTheDocument()
    expect(screen.getByText('SRV-2026-0002')).toBeInTheDocument()
    expect(screen.getAllByText('Harbor Logistics')).toHaveLength(2)
    expect(screen.getAllByText('Awaiting a reply').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Submitted').length).toBeGreaterThan(0)
    expect(screen.getAllByText('grace@harbor.test')).toHaveLength(2)
  })

  it('narrows the book by the derived state', async () => {
    renderBook()

    await screen.findByText('SRV-2026-0001')

    fireEvent.change(screen.getByLabelText('Filter by state'), {
      target: { value: 'submitted' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Filter' }))

    await waitFor(() =>
      expect(listSurveyInvitations).toHaveBeenLastCalledWith(
        expect.objectContaining({ state: 'submitted', page: 1 }),
      ),
    )
  })

  it('resends an open invitation and withdraws it', async () => {
    resendSurveyInvitation.mockResolvedValue({ ...openInvitation })
    cancelSurveyInvitation.mockResolvedValue({
      ...openInvitation,
      status: 'cancelled',
      state: 'cancelled',
      state_label: 'Cancelled',
      can_cancel: false,
      can_resend: false,
    })

    renderBook()

    await screen.findByText('SRV-2026-0001')

    fireEvent.click(screen.getByRole('button', { name: 'Resend' }))

    await waitFor(() => expect(resendSurveyInvitation).toHaveBeenCalledWith('invitation-1'))
    expect(await screen.findByText(/Sent again to grace@harbor.test/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Withdraw' }))

    await waitFor(() => expect(cancelSurveyInvitation).toHaveBeenCalledWith('invitation-1'))
    expect(
      await screen.findByText(/the link sent to grace@harbor.test no longer works/),
    ).toBeInTheDocument()
  })
})

const renderPanel = (canSend: boolean) =>
  render(
    <MemoryRouter>
      <CompanySatisfactionPanel companyId="company-1" canSend={canSend} />
    </MemoryRouter>,
  )

describe('the account satisfaction tab', () => {
  beforeEach(() => {
    listCompanySurveyInvitations.mockReset()
    listSurveys.mockReset()
    listCompanyContacts.mockReset()
    listCompanyContracts.mockReset()
    getCompanySatisfaction.mockReset()
    sendSurveyInvitation.mockReset()

    listCompanySurveyInvitations.mockResolvedValue(page([answeredInvitation]))
    getCompanySatisfaction.mockResolvedValue(card)
    listSurveys.mockResolvedValue({
      data: [activeSurvey],
      meta: { current_page: 1, last_page: 1, total: 1 },
    })
    listCompanyContacts.mockResolvedValue([])
    listCompanyContracts.mockResolvedValue({ data: [], meta: { total: 0, active: 0 } })
  })

  it('shows what the account has been asked and the score that came back', async () => {
    renderPanel(true)

    expect(await screen.findByText('Post-deployment check-in')).toBeInTheDocument()
    expect(screen.getAllByText(/CSAT 5.0 \/ 5/).length).toBeGreaterThan(0)
    expect(screen.getByText('Submitted')).toBeInTheDocument()
    expect(screen.getByText('Read every answer')).toHaveAttribute(
      'href',
      '/satisfaction/responses?company=company-1',
    )
    expect(listCompanySurveyInvitations).toHaveBeenCalledWith('company-1')
    expect(getCompanySatisfaction).toHaveBeenCalledWith('company-1')
  })

  it('sends a survey from the dialog and refreshes the account', async () => {
    sendSurveyInvitation.mockResolvedValue({
      ...openInvitation,
      id: 'invitation-3',
      reference: 'SRV-2026-0003',
      recipient_email: 'grace@client.test',
    })

    renderPanel(true)

    await screen.findByText('Post-deployment check-in')

    fireEvent.click(screen.getByRole('button', { name: 'Send survey' }))

    const dialog = await screen.findByRole('dialog', { name: 'Send a survey' })

    fireEvent.change(within(dialog).getByLabelText('Survey'), {
      target: { value: 'survey-1' },
    })
    fireEvent.change(within(dialog).getByLabelText('Address'), {
      target: { value: 'grace@client.test' },
    })
    fireEvent.change(within(dialog).getByLabelText('Note to the client'), {
      target: { value: 'How is the crew settling in?' },
    })

    fireEvent.click(within(dialog).getByRole('button', { name: 'Send survey' }))

    await waitFor(() =>
      expect(sendSurveyInvitation).toHaveBeenCalledWith('survey-1', {
        company_id: 'company-1',
        contact_id: null,
        contract_id: null,
        recipient_email: 'grace@client.test',
        message: 'How is the crew settling in?',
      }),
    )

    expect(await screen.findByText(/Sent to grace@client.test/)).toBeInTheDocument()
    expect(listCompanySurveyInvitations).toHaveBeenCalledTimes(2)
  })

  it('offers no send action to someone who may only read the account', async () => {
    renderPanel(false)

    await screen.findByText('Post-deployment check-in')

    expect(screen.queryByRole('button', { name: 'Send survey' })).not.toBeInTheDocument()
  })
})
