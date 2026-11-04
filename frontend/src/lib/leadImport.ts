import { api } from './api'
import type { LeadSource } from '../types'

/** One row of the fixed-template CSV import, mapped to lead fields. */
export interface LeadImportRow {
  line: number
  values: {
    company_name: string | null
    contact_name: string | null
    contact_email: string | null
    contact_phone: string | null
    contact_position: string | null
    source: LeadSource | null
    source_detail: string | null
    industry: string | null
    location: string | null
    service_interest: string | null
    estimated_headcount: string | null
    needed_roles: string | null
    target_start_date: string | null
    urgency: 'low' | 'medium' | 'high' | null
    notes: string | null
  }
  valid: boolean
  errors: Record<string, string[]>
  duplicates: { id: string; reference: string; company_name: string }[]
  /** Present after a commit for rows that were written. */
  lead_id: string | null
  reference: string | null
}

export interface LeadImportReport {
  file: string
  owner: { id: number; name: string } | null
  can_choose_owner: boolean
  rows: LeadImportRow[]
  summary: {
    rows: number
    valid: number
    invalid: number
    duplicates: number
  }
  /** Commit only. */
  imported?: number
  skipped?: number
}

interface ImportResponse {
  data: LeadImportReport
  message?: string
}

const buildForm = (file: File, ownerId?: number): FormData => {
  const form = new FormData()
  form.append('file', file)
  if (ownerId) form.append('owner_id', String(ownerId))
  return form
}

export const previewLeadImport = async (
  file: File,
  ownerId?: number,
): Promise<LeadImportReport> => {
  const { data } = await api.post<ImportResponse>('/leads/import/preview', buildForm(file, ownerId))
  return data.data
}

export const commitLeadImport = async (
  file: File,
  ownerId?: number,
): Promise<LeadImportReport> => {
  const { data } = await api.post<ImportResponse>('/leads/import', buildForm(file, ownerId))
  return data.data
}

/** The template is a fixed, header-only CSV; the browser downloads it like any other file. */
export const downloadLeadImportTemplate = async (): Promise<void> => {
  const { data } = await api.get<Blob>('/leads/import/template', { responseType: 'blob' })
  const url = URL.createObjectURL(data)
  const link = document.createElement('a')
  link.href = url
  link.download = 'lead-import-template.csv'
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
