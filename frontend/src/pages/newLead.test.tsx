import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import NewLead from './NewLead'
import * as leadApi from '../lib/leads'
import type { Lead } from '../types'

vi.mock('../lib/leads', () => ({
  createLead: vi.fn(),
}))

const createdLead = {
  id: 'lead-1',
  reference: 'LEAD-2026-0001',
  company_name: 'Sunrise Foods Inc',
  contact_name: 'Rosa Dela Cruz',
  contact_email: 'rosa@sunrise.test',
  contact_phone: null,
  contact_position: null,
  source: 'email',
  source_detail: null,
  industry: null,
  location: null,
  service_interest: null,
  estimated_headcount: null,
  needed_roles: null,
  target_start_date: null,
  authority_confirmed: false,
  work_site: null,
  urgency: null,
  rating: null,
  score: null,
  qualification_notes: null,
  disqualification_reason: null,
  status: 'new',
  status_changed_at: null,
  last_activity_at: null,
  next_follow_up_at: null,
  next_follow_up: null,
  notes: null,
  owner: { id: 2, name: 'Sam Salesrep' },
  owner_id: 2,
  can_claim: false,
  can_reassign: true,
  can_update: true,
  allowed_transitions: ['contacted', 'disqualified', 'archived'],
  is_archived: false,
  aging: null,
  days_since_activity: 0,
  missing_criteria: [],
  meets_criteria: false,
  can_qualify: false,
  can_convert: false,
  is_converted: false,
  converted_company_id: null,
  converted_opportunity_id: null,
  created_at: '2026-10-20T01:00:00.000000Z',
  updated_at: '2026-10-20T01:00:00.000000Z',
} satisfies Lead

const renderForm = () =>
  render(
    <MemoryRouter initialEntries={['/leads/new']}>
      <Routes>
        <Route path="/leads/new" element={<NewLead />} />
        <Route path="/leads" element={<div>Lead Workspace</div>} />
      </Routes>
    </MemoryRouter>,
  )

describe('NewLead', () => {
  it('links the saved lead and every duplicate after an advisory warning', async () => {
    vi.mocked(leadApi.createLead).mockResolvedValue({
      data: createdLead,
      duplicates: [
        {
          id: 'lead-2',
          reference: 'LEAD-2026-0002',
          company_name: 'Sunrise Foods Inc',
        },
      ],
    })

    renderForm()

    fireEvent.change(screen.getByLabelText(/Company name/), {
      target: { value: 'Sunrise Foods Inc' },
    })
    fireEvent.change(screen.getByLabelText(/Contact name/), {
      target: { value: 'Rosa Dela Cruz' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Save lead' }))

    await waitFor(() => expect(leadApi.createLead).toHaveBeenCalledTimes(1))

    expect(screen.getByText(/Lead saved — possible duplicates found/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'LEAD-2026-0001' })).toHaveAttribute(
      'href',
      '/leads/lead-1',
    )
    expect(screen.getByRole('link', { name: 'LEAD-2026-0002' })).toHaveAttribute(
      'href',
      '/leads/lead-2',
    )
  })
})
