import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PublicLeadForm from './PublicLeadForm'

const { submitPublicLead } = vi.hoisted(() => ({ submitPublicLead: vi.fn() }))

vi.mock('../../lib/publicLeads', () => ({ submitPublicLead }))

const fillRequired = () => {
  fireEvent.change(screen.getByLabelText(/Company name/), {
    target: { value: ' Northpoint Retail ' },
  })
  fireEvent.change(screen.getByLabelText(/Your name/), { target: { value: ' Ana Cruz ' } })
  fireEvent.change(screen.getByLabelText('Email'), {
    target: { value: ' ana@northpoint.example ' },
  })
  fireEvent.click(screen.getByRole('checkbox', { name: /I agree to be contacted/ }))
}

const submit = () => fireEvent.click(screen.getByRole('button', { name: 'Send request' }))

describe('public lead form', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    submitPublicLead.mockResolvedValue({
      message: 'Thank you — your staffing request has been sent. Our team will be in touch.',
    })
  })

  it('submits the trimmed brief and shows the thank-you state', async () => {
    const { container } = render(<PublicLeadForm />)

    // The honeypot is present for bots but not a field a person fills in.
    const honeypot = container.querySelector('input[name="website"]')
    expect(honeypot).toHaveAttribute('tabindex', '-1')
    expect(honeypot).toHaveAttribute('autocomplete', 'off')

    fillRequired()
    fireEvent.change(screen.getByLabelText('People needed'), { target: { value: '12' } })
    fireEvent.change(screen.getByLabelText('Roles needed'), {
      target: { value: ' Warehouse associates ' },
    })

    submit()

    await waitFor(() => expect(submitPublicLead).toHaveBeenCalledTimes(1))
    expect(submitPublicLead).toHaveBeenCalledWith({
      company_name: 'Northpoint Retail',
      contact_name: 'Ana Cruz',
      contact_email: 'ana@northpoint.example',
      contact_phone: undefined,
      industry: undefined,
      service_interest: undefined,
      estimated_headcount: 12,
      needed_roles: 'Warehouse associates',
      target_start_date: undefined,
      message: undefined,
      consent: true,
      website: '',
    })

    expect(await screen.findByRole('status')).toHaveTextContent('Request received')
    expect(screen.getByRole('status')).toHaveTextContent(
      'Thank you — your staffing request has been sent.',
    )
    expect(screen.queryByRole('button', { name: 'Send request' })).not.toBeInTheDocument()
  })

  it('shows server validation errors beside the fields', async () => {
    submitPublicLead.mockRejectedValue({
      response: {
        status: 422,
        data: {
          message: 'Please correct the highlighted fields.',
          errors: {
            company_name: ['The company name field is required.'],
            contact_email: ['Give us an email address or a phone number.'],
            contact_phone: ['Give us an email address or a phone number.'],
            consent: ['Please agree to be contacted about this request.'],
          },
        },
      },
    })

    render(<PublicLeadForm />)
    submit()

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Please correct the highlighted fields.',
    )
    expect(screen.getByText('The company name field is required.')).toBeInTheDocument()
    expect(screen.getByText('Please agree to be contacted about this request.')).toBeInTheDocument()
    expect(screen.getByLabelText(/Company name/)).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByRole('checkbox', { name: /I agree to be contacted/ })).toHaveAttribute(
      'aria-invalid',
      'true',
    )
  })

  it('explains a throttle response and keeps the form open', async () => {
    submitPublicLead.mockRejectedValue({
      isAxiosError: true,
      response: { status: 429 },
    })

    render(<PublicLeadForm />)
    submit()

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Too many requests from this browser. Please wait a minute and try again.',
    )
    expect(screen.getByRole('button', { name: 'Send request' })).toBeEnabled()
  })

  it('keeps the visitor’s details when the request fails and invites a retry', async () => {
    submitPublicLead.mockRejectedValue(new Error('Network Error'))

    render(<PublicLeadForm />)
    fillRequired()
    submit()

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'We could not send your request just now. Please try again',
    )
    expect(screen.getByLabelText(/Company name/)).toHaveValue(' Northpoint Retail ')
    expect(screen.getByRole('button', { name: 'Send request' })).toBeEnabled()
  })
})
