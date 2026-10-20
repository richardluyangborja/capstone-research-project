import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Surveys from './Surveys'
import SurveyForm from './SurveyForm'
import * as surveyApi from '../lib/surveys'
import type { Survey } from '../types'

// `vi.mock` factories are hoisted above the imports, so fixtures and the mutable session the auth
// mock reads must come from `vi.hoisted`.
const { auth, checkIn, retired, book, repBook, editable } = vi.hoisted(() => {
  const auth = { role: 'manager' as 'manager' | 'salesrep' }

  const base = {
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

  const checkIn: Survey = {
    ...base,
    id: 'survey-1',
    name: 'Post-deployment check-in',
    description: 'Sent after the first weeks of a deployment.',
    is_active: true,
    questions_count: 3,
  }

  const pilot: Survey = {
    ...base,
    id: 'survey-2',
    name: 'Deployment pulse (pilot)',
    description: 'A short pulse still being written.',
    is_active: false,
    questions_count: 1,
  }

  // Soft-deleted: out of the library's default view and only Restore remains.
  const retired: Survey = {
    ...base,
    id: 'survey-3',
    name: 'Client referral pulse',
    description: 'A retired pulse from the referral push.',
    is_active: true,
    can_update: false,
    can_delete: false,
    can_duplicate: false,
    deleted_at: '2026-10-20T03:00:00.000000Z',
    questions_count: 2,
  }

  const book = {
    data: [checkIn, pilot, retired],
    meta: { current_page: 1, per_page: 25, total: 3, last_page: 1 },
  }

  // What the API sends a salesrep: the same rows, readable but not theirs to change.
  const repBook = {
    data: [checkIn, pilot].map((survey) => ({
      ...survey,
      can_update: false,
      can_delete: false,
      can_restore: false,
      can_duplicate: false,
    })),
    meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
  }

  const editable: Survey = {
    ...checkIn,
    questions: [
      {
        id: 'q-1',
        type: { value: 'rating', label: 'Rating (1–5)' },
        prompt: 'How satisfied are you with the deployment?',
        help: 'From day one to today.',
        required: true,
        position: 1,
        options: [],
      },
      {
        id: 'q-2',
        type: { value: 'single_choice', label: 'Single choice' },
        prompt: 'How is the headcount matching your needs?',
        help: null,
        required: true,
        position: 2,
        options: ['Short of what we need', 'Just right'],
      },
    ],
  }

  return { auth, checkIn, pilot, retired, book, repBook, editable }
})

vi.mock('../lib/auth-context', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Maria Manager', role: auth.role }, logout: vi.fn() }),
}))

vi.mock('../lib/surveys', () => ({
  listSurveys: vi.fn(),
  getSurvey: vi.fn(),
  createSurvey: vi.fn(),
  updateSurvey: vi.fn(),
  retireSurvey: vi.fn(),
  restoreSurvey: vi.fn(),
  duplicateSurvey: vi.fn(),
}))

vi.mock('../lib/leads', () => ({
  parseValidationError: vi.fn().mockReturnValue(null),
}))

beforeEach(() => {
  auth.role = 'manager'
  vi.mocked(surveyApi.listSurveys).mockResolvedValue(book)
  vi.mocked(surveyApi.getSurvey).mockResolvedValue(editable)
  vi.mocked(surveyApi.createSurvey).mockResolvedValue(checkIn)
  vi.mocked(surveyApi.updateSurvey).mockResolvedValue(editable)
  vi.mocked(surveyApi.retireSurvey).mockResolvedValue(undefined)
  vi.mocked(surveyApi.restoreSurvey).mockResolvedValue(retired)
  vi.mocked(surveyApi.duplicateSurvey).mockResolvedValue({
    ...checkIn,
    id: 'survey-4',
    name: 'Post-deployment check-in (copy)',
    is_active: false,
  })
})

const renderBook = () =>
  render(
    <MemoryRouter>
      <Surveys />
    </MemoryRouter>,
  )

const renderForm = (id?: string) =>
  render(
    <MemoryRouter initialEntries={[id ? `/surveys/${id}` : '/surveys/new']}>
      <Routes>
        <Route path="/surveys/new" element={<SurveyForm />} />
        <Route path="/surveys/:id" element={<SurveyForm />} />
      </Routes>
    </MemoryRouter>,
  )

describe('Survey library', () => {
  it('lists the surveys with their state, question count and author', async () => {
    renderBook()

    const row = (await screen.findByRole('link', { name: 'Post-deployment check-in' })).closest(
      'tr',
    ) as HTMLElement

    // An active survey can be sent and can be edited, duplicated or retired.
    expect(within(row).getByText('Active')).toBeInTheDocument()
    expect(within(row).getByText('3 questions')).toBeInTheDocument()
    expect(within(row).getByText('Maria Manager')).toBeInTheDocument()
    expect(within(row).getByRole('button', { name: 'Duplicate' })).toBeInTheDocument()
    expect(within(row).getByRole('button', { name: 'Retire' })).toBeInTheDocument()

    // A draft is written but not sendable; a retired one is only restorable.
    const pilotRow = screen.getByText('Deployment pulse (pilot)').closest('tr') as HTMLElement
    expect(within(pilotRow).getByText('Draft')).toBeInTheDocument()
    expect(within(pilotRow).getByText('1 question')).toBeInTheDocument()

    const retiredRow = screen.getByText('Client referral pulse').closest('tr') as HTMLElement
    expect(within(retiredRow).getByText('Retired')).toBeInTheDocument()
    expect(within(retiredRow).getByRole('button', { name: 'Restore' })).toBeInTheDocument()
    expect(within(retiredRow).queryByRole('button', { name: 'Retire' })).not.toBeInTheDocument()
  })

  it('offers a salesrep the library but no way to change it', async () => {
    auth.role = 'salesrep'
    vi.mocked(surveyApi.listSurveys).mockResolvedValue(repBook)

    renderBook()

    await screen.findByText('Post-deployment check-in')

    expect(screen.queryByRole('link', { name: 'New survey' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Duplicate' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Retire' })).not.toBeInTheDocument()
  })

  it('retires a survey and says what that means for what it gathered', async () => {
    renderBook()

    const row = (await screen.findByRole('link', { name: 'Post-deployment check-in' })).closest(
      'tr',
    ) as HTMLElement
    fireEvent.click(within(row).getByRole('button', { name: 'Retire' }))

    await waitFor(() => expect(surveyApi.retireSurvey).toHaveBeenCalledWith('survey-1'))
    expect(await screen.findByText(/retired — it can no longer be sent/)).toBeInTheDocument()
  })

  it('duplicates a survey into a draft and names the copy', async () => {
    renderBook()

    const row = (await screen.findByRole('link', { name: 'Post-deployment check-in' })).closest(
      'tr',
    ) as HTMLElement
    fireEvent.click(within(row).getByRole('button', { name: 'Duplicate' }))

    await waitFor(() => expect(surveyApi.duplicateSurvey).toHaveBeenCalledWith('survey-1'))
    expect(
      await screen.findByText(/Copied into “Post-deployment check-in \(copy\)”/),
    ).toBeInTheDocument()
  })

  it('restores a retired survey', async () => {
    renderBook()

    const row = (await screen.findByText('Client referral pulse')).closest('tr') as HTMLElement
    fireEvent.click(within(row).getByRole('button', { name: 'Restore' }))

    await waitFor(() => expect(surveyApi.restoreSurvey).toHaveBeenCalledWith('survey-3'))
    expect(await screen.findByText(/is back in the library/)).toBeInTheDocument()
  })
})

describe('Survey form', () => {
  it('creates a survey with the questions in the order they were written', async () => {
    renderForm()

    fireEvent.change(screen.getByLabelText('Name'), {
      target: { value: '  Post-deployment check-in  ' },
    })
    fireEvent.change(screen.getByLabelText('Description'), {
      target: { value: 'Sent after the first weeks.' },
    })

    // The first question stays a rating; the second becomes a choice with its options.
    fireEvent.change(screen.getByLabelText('Prompt'), {
      target: { value: 'How satisfied are you with the deployment?' },
    })

    fireEvent.click(screen.getByRole('button', { name: 'Add question' }))
    fireEvent.change(screen.getAllByLabelText('Type')[1], {
      target: { value: 'single_choice' },
    })
    fireEvent.change(screen.getAllByLabelText('Prompt')[1], {
      target: { value: 'How is the headcount matching your needs?' },
    })
    fireEvent.change(screen.getByLabelText('Option 1 of question 2'), {
      target: { value: 'Short of what we need' },
    })
    fireEvent.change(screen.getByLabelText('Option 2 of question 2'), {
      target: { value: 'Just right' },
    })

    fireEvent.click(screen.getByRole('button', { name: 'Create survey' }))

    await waitFor(() =>
      expect(surveyApi.createSurvey).toHaveBeenCalledWith({
        name: 'Post-deployment check-in',
        description: 'Sent after the first weeks.',
        is_active: false,
        questions: [
          {
            type: 'rating',
            prompt: 'How satisfied are you with the deployment?',
            help: null,
            required: true,
          },
          {
            type: 'single_choice',
            prompt: 'How is the headcount matching your needs?',
            help: null,
            required: true,
            options: ['Short of what we need', 'Just right'],
          },
        ],
      }),
    )
  })

  it('loads a survey into the builder and saves the edits', async () => {
    renderForm('survey-1')

    await waitFor(() =>
      expect(screen.getByLabelText('Name')).toHaveValue('Post-deployment check-in'),
    )

    expect(screen.getAllByLabelText('Prompt')[0]).toHaveValue(
      'How satisfied are you with the deployment?',
    )
    expect(screen.getAllByLabelText('Type')[1]).toHaveValue('single_choice')
    expect(screen.getByLabelText('Option 2 of question 2')).toHaveValue('Just right')

    fireEvent.change(screen.getByLabelText('Name'), {
      target: { value: 'Post-deployment check-in (2026)' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Save survey' }))

    await waitFor(() =>
      expect(surveyApi.updateSurvey).toHaveBeenCalledWith(
        'survey-1',
        expect.objectContaining({ name: 'Post-deployment check-in (2026)' }),
      ),
    )
  })

  it('shows what the API refused rather than swallowing it', async () => {
    const { parseValidationError } = await import('../lib/leads')
    vi.mocked(parseValidationError).mockReturnValue({
      message: 'The given data was invalid.',
      errors: { questions: ['A survey needs at least one question before it can be active.'] },
    })
    vi.mocked(surveyApi.createSurvey).mockRejectedValue(new Error('422'))

    renderForm()

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Empty survey' } })
    fireEvent.click(screen.getByLabelText('Active — can be sent to a client'))
    fireEvent.click(screen.getByRole('button', { name: 'Remove question 1' }))
    fireEvent.click(screen.getByRole('button', { name: 'Create survey' }))

    expect(await screen.findByText('The given data was invalid.')).toBeInTheDocument()
    // The general question-set error shows in the alert and again beside the builder.
    expect(
      screen.getAllByText('A survey needs at least one question before it can be active.').length,
    ).toBeGreaterThan(0)
  })

  it('keeps a retired survey read-only', async () => {
    vi.mocked(surveyApi.getSurvey).mockResolvedValue({
      ...editable,
      deleted_at: '2026-10-20T03:00:00.000000Z',
    })

    renderForm('survey-1')

    await waitFor(() =>
      expect(screen.getByText(/This survey is retired, so it can no longer be sent/)).toBeInTheDocument(),
    )
    expect(screen.getByLabelText('Name')).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Save survey' })).toBeDisabled()
  })
})
