import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PublicSurvey from './PublicSurvey'
import type { PublicSurvey as PublicSurveyPayload } from '../types'

// Module 05 slice S2 — the client's page. The page is standalone (no session, no shell), so the
// only thing to mock is the two public calls it makes.

const { getPublicSurvey, submitPublicSurvey, survey } = vi.hoisted(() => {
  const survey: PublicSurveyPayload = {
    survey: {
      id: 'survey-1',
      name: 'Post-deployment check-in',
      description: 'How the first weeks went.',
      questions: [
        {
          id: 'q-rating',
          type: 'rating',
          prompt: 'How satisfied are you with the deployment?',
          help: null,
          required: true,
          options: [],
          bounds: { min: 1, max: 5 },
        },
        {
          id: 'q-nps',
          type: 'nps',
          prompt: 'How likely are you to recommend us?',
          help: '0 = not at all likely, 10 = extremely likely.',
          required: true,
          options: [],
          bounds: { min: 0, max: 10 },
        },
        {
          id: 'q-text',
          type: 'text',
          prompt: 'Anything we should know?',
          help: null,
          required: false,
          options: [],
          bounds: null,
        },
      ],
    },
    company: { name: 'Harbor Logistics' },
    contact: { name: 'Grace Lim' },
    expires_at: '2026-11-03T02:00:00.000000Z',
  }

  return {
    getPublicSurvey: vi.fn(),
    submitPublicSurvey: vi.fn(),
    survey,
  }
})

vi.mock('../lib/publicSurvey', () => ({
  getPublicSurvey: (...args: unknown[]) => getPublicSurvey(...args),
  submitPublicSurvey: (...args: unknown[]) => submitPublicSurvey(...args),
}))

const renderPage = () =>
  render(
    <MemoryRouter initialEntries={['/survey/token-abc']}>
      <Routes>
        <Route path="/survey/:token" element={<PublicSurvey />} />
      </Routes>
    </MemoryRouter>,
  )

/** The question's list item, so its scale can be clicked without colliding with the other one. */
const questionBlock = (prompt: RegExp) => {
  const heading = screen.getByText(prompt)
  const item = heading.closest('li')

  if (!item) throw new Error(`No list item for ${String(prompt)}`)

  return within(item)
}

describe('the client survey page', () => {
  beforeEach(() => {
    getPublicSurvey.mockReset()
    submitPublicSurvey.mockReset()
    getPublicSurvey.mockResolvedValue(structuredClone(survey))
  })

  it('asks the questions without a session and names the account in the greeting', async () => {
    renderPage()

    expect(await screen.findByText('Post-deployment check-in')).toBeInTheDocument()
    expect(screen.getByText('How the first weeks went.')).toBeInTheDocument()
    expect(screen.getByText(/Hi Grace, thanks for taking a moment/)).toBeInTheDocument()
    expect(getPublicSurvey).toHaveBeenCalledWith('token-abc')

    expect(questionBlock(/How satisfied are you/).getAllByRole('button')).toHaveLength(5)
    expect(questionBlock(/How likely are you to recommend/).getAllByRole('button')).toHaveLength(11)
  })

  it('submits only the answers that were given, in the survey order', async () => {
    submitPublicSurvey.mockResolvedValue({
      id: 'response-1',
      submitted_at: '2026-10-20T02:00:00.000000Z',
      csat: 5,
      nps: 10,
      band: 'promoter',
      needs_attention: false,
      created_at: '2026-10-20T02:00:00.000000Z',
    })

    renderPage()

    await screen.findByText('Post-deployment check-in')

    fireEvent.click(questionBlock(/How satisfied are you/).getByRole('button', { name: '5' }))
    fireEvent.click(questionBlock(/How likely are you to recommend/).getByRole('button', { name: '10' }))
    fireEvent.change(screen.getByPlaceholderText('Your answer'), {
      target: { value: 'The crew settled in quickly.' },
    })

    fireEvent.click(screen.getByRole('button', { name: 'Send my answers' }))

    await waitFor(() => expect(submitPublicSurvey).toHaveBeenCalledTimes(1))

    expect(submitPublicSurvey).toHaveBeenCalledWith('token-abc', [
      { question_id: 'q-rating', value_number: 5 },
      { question_id: 'q-nps', value_number: 10 },
      { question_id: 'q-text', value_text: 'The crew settled in quickly.' },
    ])

    expect(await screen.findByText(/Thank you — your answers are in/)).toBeInTheDocument()
    expect(screen.getByText(/Harbor Logistics/)).toBeInTheDocument()
  })

  it('stops a blank required answer before it reaches the API', async () => {
    renderPage()

    await screen.findByText('Post-deployment check-in')

    fireEvent.click(screen.getByRole('button', { name: 'Send my answers' }))

    expect(
      await screen.findByText('Please answer the questions marked as required.'),
    ).toBeInTheDocument()
    expect(screen.getAllByText('Please answer this question.')).toHaveLength(2)
    expect(submitPublicSurvey).not.toHaveBeenCalled()
  })

  it('says why a link that is no longer usable does not open', async () => {
    getPublicSurvey.mockRejectedValue({
      isAxiosError: true,
      response: { status: 410, data: { message: 'This survey link has expired.' } },
    })

    renderPage()

    expect(await screen.findByText('This link cannot be used')).toBeInTheDocument()
    expect(screen.getByText('This survey link has expired.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Send my answers' })).not.toBeInTheDocument()
  })
})
