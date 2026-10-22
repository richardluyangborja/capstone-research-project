import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Satisfaction from './Satisfaction'
import SurveyResponses from './SurveyResponses'
import SurveyResponseDetail from './SurveyResponseDetail'
import type { SatisfactionSummary, SurveyResponseSummary } from '../types'

// Module 05 slice S3 — the summary, the response book and one answer read back. The pages only
// read, so the mocks are the four calls they make.

const {
  getSatisfactionSummary,
  listSurveyResponses,
  getSurveyResponse,
  resolveSurveyResponse,
  listSurveys,
  summary,
  answer,
} = vi.hoisted(() => {
  const answer: SurveyResponseSummary = {
    id: 'response-1',
    submitted_at: '2026-10-19T02:00:00.000000Z',
    csat: 2,
    nps: 4,
    band: 'detractor',
    band_label: 'Detractor',
    needs_attention: true,
    resolved: false,
    resolved_at: null,
    resolved_by: null,
    resolution_note: null,
    can_resolve: true,
    survey: { id: 'survey-1', name: 'Post-deployment check-in' },
    company: { id: 'company-1', name: 'Sunrise Foods Inc' },
    contact: { id: 'contact-1', name: 'Grace Lim' },
    invitation: { id: 'invitation-2', reference: 'SRV-2026-0002' },
    answers: [
      {
        id: 'answer-1',
        question_id: 'q-rating',
        question: {
          id: 'q-rating',
          type: 'rating',
          prompt: 'How satisfied are you with the deployment?',
          options: null,
        },
        value_number: 2,
        value_text: null,
        value_options: null,
      },
      {
        id: 'answer-2',
        question_id: 'q-nps',
        question: {
          id: 'q-nps',
          type: 'nps',
          prompt: 'How likely are you to recommend us?',
          options: null,
        },
        value_number: 4,
        value_text: null,
        value_options: null,
      },
      {
        id: 'answer-3',
        question_id: 'q-text',
        question: {
          id: 'q-text',
          type: 'text',
          prompt: 'Anything we should know?',
          options: null,
        },
        value_number: null,
        value_text: 'The replacement crew arrived without the site briefing.',
        value_options: null,
      },
    ],
    created_at: '2026-10-19T02:00:00.000000Z',
  }

  const summary: SatisfactionSummary = {
    responses: 3,
    csat: 3.5,
    nps: 40,
    promoters: 2,
    passives: 0,
    detractors: 1,
    answered: 3,
    attention: 1,
    invitations: 5,
    response_rate: 60,
    recent: [answer],
    attention_responses: [answer],
  }

  return {
    getSatisfactionSummary: vi.fn(),
    listSurveyResponses: vi.fn(),
    getSurveyResponse: vi.fn(),
    resolveSurveyResponse: vi.fn(),
    listSurveys: vi.fn(),
    summary,
    answer,
  }
})

vi.mock('../lib/satisfaction', () => ({
  getSatisfactionSummary: (...args: unknown[]) => getSatisfactionSummary(...args),
  listSurveyResponses: (...args: unknown[]) => listSurveyResponses(...args),
  getSurveyResponse: (...args: unknown[]) => getSurveyResponse(...args),
  resolveSurveyResponse: (...args: unknown[]) => resolveSurveyResponse(...args),
}))

vi.mock('../lib/surveys', () => ({
  listSurveys: (...args: unknown[]) => listSurveys(...args),
}))

const page = (rows: SurveyResponseSummary[]) => ({
  data: rows,
  meta: { current_page: 1, last_page: 1, total: rows.length, per_page: 25 },
})

describe('the satisfaction summary', () => {
  beforeEach(() => {
    getSatisfactionSummary.mockReset()
    getSatisfactionSummary.mockResolvedValue(structuredClone(summary))
  })

  it('shows the derived numbers, the promoter split and the latest answers', async () => {
    render(
      <MemoryRouter>
        <Satisfaction />
      </MemoryRouter>,
    )

    expect(await screen.findByText('3.5 / 5')).toBeInTheDocument()
    expect(screen.getByText('+40')).toBeInTheDocument()
    expect(screen.getByText('60.0%')).toBeInTheDocument()
    expect(screen.getAllByText('Needs attention').length).toBeGreaterThan(0)

    // Two promoters of three answered: the split reads as a share, not just a count.
    expect(screen.getByText('2 · 67%')).toBeInTheDocument()
    expect(screen.getByText('1 · 33%')).toBeInTheDocument()

    expect(screen.getAllByText('Sunrise Foods Inc').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Grace Lim').length).toBeGreaterThan(0)
    expect(screen.getByRole('link', { name: 'Read' })).toHaveAttribute(
      'href',
      '/satisfaction/responses/response-1',
    )
  })

  it('lists who is still waiting on a call back', async () => {
    render(
      <MemoryRouter>
        <Satisfaction />
      </MemoryRouter>,
    )

    expect(await screen.findByText('Waiting on a call back')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Read & resolve/ })).toHaveAttribute(
      'href',
      '/satisfaction/responses/response-1',
    )
  })

  it('invites a first send when nothing has been answered', async () => {
    getSatisfactionSummary.mockResolvedValue({
      ...structuredClone(summary),
      responses: 0,
      csat: null,
      nps: null,
      promoters: 0,
      passives: 0,
      detractors: 0,
      answered: 0,
      attention: 0,
      invitations: 0,
      response_rate: null,
      recent: [],
    })

    render(
      <MemoryRouter>
        <Satisfaction />
      </MemoryRouter>,
    )

    expect(await screen.findByText('No answers yet')).toBeInTheDocument()
  })
})

describe('the response book', () => {
  beforeEach(() => {
    listSurveyResponses.mockReset()
    listSurveys.mockReset()
    listSurveyResponses.mockResolvedValue(page([answer]))
    listSurveys.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
  })

  const renderBook = (entry = '/satisfaction/responses') =>
    render(
      <MemoryRouter initialEntries={[entry]}>
        <SurveyResponses />
      </MemoryRouter>,
    )

  it('lists the answer with its account, score and follow-through', async () => {
    renderBook()

    expect(await screen.findByText('Sunrise Foods Inc')).toBeInTheDocument()
    expect(screen.getByText('2.0 / 5')).toBeInTheDocument()
    expect(screen.getAllByText('Detractor').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Needs attention').length).toBeGreaterThan(0)
    expect(screen.getByText('SRV-2026-0002')).toBeInTheDocument()
    expect(listSurveyResponses).toHaveBeenCalledWith(expect.objectContaining({ page: 1 }))
  })

  it('narrows the book by band and follow-through', async () => {
    renderBook()

    await screen.findByText('Sunrise Foods Inc')

    fireEvent.change(screen.getByLabelText('Filter by band'), { target: { value: 'detractor' } })
    fireEvent.change(screen.getByLabelText('Filter by follow-through'), {
      target: { value: 'true' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Filter' }))

    await waitFor(() =>
      expect(listSurveyResponses).toHaveBeenLastCalledWith(
        expect.objectContaining({ band: 'detractor', attention: 'true' }),
      ),
    )
  })

  it('reads one account’s answers when the account card links here', async () => {
    renderBook('/satisfaction/responses?company=company-1')

    expect(await screen.findByText(/Showing one account’s answers/)).toBeInTheDocument()
    expect(listSurveyResponses).toHaveBeenCalledWith(
      expect.objectContaining({ company: 'company-1' }),
    )
  })

  it('marks an answer resolved once the loop is closed', async () => {
    listSurveyResponses.mockResolvedValue(
      page([
        {
          ...structuredClone(answer),
          resolved: true,
          resolved_at: '2026-10-20T03:00:00.000000Z',
          resolved_by: { id: 2, name: 'Maria Santos' },
          resolution_note: 'Called Grace and confirmed the Monday crew.',
          can_resolve: false,
        },
      ]),
    )

    renderBook()

    expect(await screen.findByText('Resolved')).toBeInTheDocument()
  })
})

describe('one answer read back', () => {
  beforeEach(() => {
    getSurveyResponse.mockReset()
    resolveSurveyResponse.mockReset()
    getSurveyResponse.mockResolvedValue(structuredClone(answer))
  })

  const renderDetail = () =>
    render(
      <MemoryRouter initialEntries={['/satisfaction/responses/response-1']}>
        <Routes>
          <Route path="/satisfaction/responses/:id" element={<SurveyResponseDetail />} />
        </Routes>
      </MemoryRouter>,
    )

  it('shows the questions in the order they were asked, with the answers', async () => {
    renderDetail()

    const first = await screen.findByText(/1\. How satisfied are you with the deployment\?/)
    const third = screen.getByText(/3\. Anything we should know\?/)

    expect(first.compareDocumentPosition(third) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(screen.getByText('2 / 5')).toBeInTheDocument()
    expect(screen.getAllByText('4 / 10').length).toBeGreaterThan(0)
    expect(
      screen.getByText('The replacement crew arrived without the site briefing.'),
    ).toBeInTheDocument()
  })

  it('flags an answer that crossed the unhappy line', async () => {
    renderDetail()

    const alert = await screen.findByRole('alert')

    expect(within(alert).getByText('This client needs a call back')).toBeInTheDocument()
    expect(within(alert).getByText(/CSAT at or below 3, or a detractor score/)).toBeInTheDocument()
  })

  it('closes the loop with a note and shows who resolved it', async () => {
    resolveSurveyResponse.mockResolvedValue({
      ...structuredClone(answer),
      resolved: true,
      resolved_at: '2026-10-20T03:00:00.000000Z',
      resolved_by: { id: 2, name: 'Maria Santos' },
      resolution_note: 'Called Grace and confirmed the Monday crew.',
      can_resolve: false,
    })

    renderDetail()

    fireEvent.change(await screen.findByLabelText('Resolution note'), {
      target: { value: 'Called Grace and confirmed the Monday crew.' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Resolve response' }))

    expect(await screen.findByText('The loop is closed')).toBeInTheDocument()
    expect(screen.getByText('Called Grace and confirmed the Monday crew.')).toBeInTheDocument()
    expect(screen.getByText(/Maria Santos/)).toBeInTheDocument()
    expect(resolveSurveyResponse).toHaveBeenCalledWith(
      'response-1',
      'Called Grace and confirmed the Monday crew.',
    )
  })

  it('says so when the answer cannot be opened', async () => {
    getSurveyResponse.mockRejectedValue(new Error('403'))

    renderDetail()

    expect(await screen.findByText(/Could not open that answer/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Back to the responses/ })).toBeInTheDocument()
  })
})
