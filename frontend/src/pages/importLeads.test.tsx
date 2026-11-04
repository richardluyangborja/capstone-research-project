import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ImportLeads from './ImportLeads'
import type { LeadImportReport } from '../lib/leadImport'

const { useAuth, previewLeadImport, commitLeadImport, downloadLeadImportTemplate, getAssignableUsers } =
  vi.hoisted(() => ({
    useAuth: vi.fn(),
    previewLeadImport: vi.fn(),
    commitLeadImport: vi.fn(),
    downloadLeadImportTemplate: vi.fn(),
    getAssignableUsers: vi.fn(),
  }))

vi.mock('../lib/auth-context', () => ({ useAuth }))
vi.mock('../lib/leadImport', () => ({
  previewLeadImport,
  commitLeadImport,
  downloadLeadImportTemplate,
}))
vi.mock('../lib/leads', () => ({ getAssignableUsers }))

const report = (overrides: Partial<LeadImportReport> = {}): LeadImportReport => ({
  file: 'leads.csv',
  owner: null,
  can_choose_owner: true,
  rows: [
    {
      line: 2,
      values: {
        company_name: 'Northpoint Retail',
        contact_name: 'Ana Cruz',
        contact_email: 'ana@northpoint.example',
        contact_phone: null,
        contact_position: null,
        source: 'referral',
        source_detail: null,
        industry: null,
        location: null,
        service_interest: null,
        estimated_headcount: '12',
        needed_roles: null,
        target_start_date: null,
        urgency: null,
        notes: null,
      },
      valid: true,
      errors: {},
      duplicates: [],
      lead_id: null,
      reference: null,
    },
  ],
  summary: { rows: 1, valid: 1, invalid: 0, duplicates: 0 },
  ...overrides,
})

const renderPage = () =>
  render(
    <MemoryRouter>
      <ImportLeads />
    </MemoryRouter>,
  )

const chooseFile = () => {
  const input = screen.getByLabelText('CSV file') as HTMLInputElement
  fireEvent.change(input, {
    target: { files: [new File(['company_name'], 'leads.csv', { type: 'text/csv' })] },
  })
}

describe('lead import page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuth.mockReturnValue({ user: { id: 1, name: 'Sam Salesrep', role: 'salesrep' } })
    getAssignableUsers.mockResolvedValue([])
    previewLeadImport.mockResolvedValue(report())
    commitLeadImport.mockResolvedValue(report({ imported: 1, skipped: 0 }))
    downloadLeadImportTemplate.mockResolvedValue(undefined)
  })

  it('previews and imports a salesrep file that is owned by the importer', async () => {
    const repReport = report({ owner: { id: 1, name: 'Sam Salesrep' }, can_choose_owner: false })
    previewLeadImport.mockResolvedValue(repReport)
    commitLeadImport.mockResolvedValue({ ...repReport, imported: 1, skipped: 0 })

    renderPage()

    expect(screen.queryByLabelText('Assign imported leads to')).not.toBeInTheDocument()
    expect(screen.getByText('Your imported leads will be assigned to you.')).toBeInTheDocument()

    chooseFile()
    fireEvent.click(screen.getByRole('button', { name: 'Preview file' }))

    await waitFor(() => expect(previewLeadImport).toHaveBeenCalledTimes(1))
    expect(previewLeadImport.mock.calls[0][1]).toBeUndefined()

    expect(await screen.findByText('Northpoint Retail')).toBeInTheDocument()
    expect(screen.getByText('Sam Salesrep')).toBeInTheDocument()
    expect(screen.getByText('Ready')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Import 1 valid lead(s)' }))

    await waitFor(() => expect(commitLeadImport).toHaveBeenCalledTimes(1))
    expect(await screen.findByRole('alert')).toHaveTextContent('1 lead(s) imported')
    expect(screen.getByRole('link', { name: 'Open the Lead Workspace' })).toHaveAttribute(
      'href',
      '/leads',
    )
    expect(screen.getByRole('button', { name: 'Import 1 valid lead(s)' })).toBeDisabled()
  })

  it('lets a manager choose a target owner in scope', async () => {
    useAuth.mockReturnValue({ user: { id: 2, name: 'Maria Manager', role: 'manager' } })
    getAssignableUsers.mockResolvedValue([
      { id: 7, name: 'Liza Salesrep', open_leads_count: 4 },
    ])
    const managerReport = report({ owner: { id: 7, name: 'Liza Salesrep' } })
    previewLeadImport.mockResolvedValue(managerReport)

    renderPage()

    await waitFor(() =>
      expect(
        screen.getByRole('option', { name: 'Liza Salesrep (4 open leads)' }),
      ).toBeInTheDocument(),
    )

    chooseFile()
    fireEvent.change(screen.getByLabelText('Assign imported leads to'), {
      target: { value: '7' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Preview file' }))

    await waitFor(() => expect(previewLeadImport).toHaveBeenCalledTimes(1))
    expect(previewLeadImport.mock.calls[0][1]).toBe(7)
    expect(await screen.findByText('Liza Salesrep')).toBeInTheDocument()
  })

  it('shows per-row validation errors and refuses to import an empty valid set', async () => {
    previewLeadImport.mockResolvedValue(
      report({
        rows: [
          {
            ...report().rows[0],
            line: 3,
            values: {
              ...report().rows[0].values,
              company_name: null,
              contact_email: null,
            },
            valid: false,
            errors: { company_name: ['The company name field is required.'] },
          },
        ],
        summary: { rows: 1, valid: 0, invalid: 1, duplicates: 0 },
      }),
    )

    renderPage()
    chooseFile()
    fireEvent.click(screen.getByRole('button', { name: 'Preview file' }))

    expect(
      await screen.findByText('The company name field is required.'),
    ).toBeInTheDocument()
    expect(screen.getByText('Nothing can be imported yet')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Import 0 valid lead(s)' })).toBeDisabled()
  })

  it('surfaces file validation errors from the API', async () => {
    previewLeadImport.mockRejectedValue({
      response: {
        status: 422,
        data: {
          message: 'The given data was invalid.',
          errors: { file: ['A CSV file may contain at most 500 rows.'] },
        },
      },
    })

    renderPage()
    chooseFile()
    fireEvent.click(screen.getByRole('button', { name: 'Preview file' }))

    expect(await screen.findByText('A CSV file may contain at most 500 rows.')).toBeInTheDocument()
  })

  it('downloads the fixed template', async () => {
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Download template' }))

    await waitFor(() => expect(downloadLeadImportTemplate).toHaveBeenCalledTimes(1))
  })
})
