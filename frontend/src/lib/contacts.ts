import { api } from './api'
import type { Contact, DuplicateContact, Paginated } from '../types'

export interface ContactFilters {
  search?: string
  role_type?: string
  status?: string
  company_id?: string
  page?: number
}

/** `company_id` is never sent on update: a contact belongs to one company (spec 07 §1). */
export interface ContactInput {
  name?: string
  position?: string | null
  role_type?: string
  email?: string | null
  phone?: string | null
  mobile?: string | null
  is_primary?: boolean
  status?: string
  notes?: string | null
}

export interface ContactSaveResponse {
  contact: Contact
  duplicates: DuplicateContact[]
}

const buildParams = (filters: ContactFilters): Record<string, string> => {
  const params: Record<string, string> = {}
  if (filters.search) params.search = filters.search
  if (filters.role_type) params['filter[role_type]'] = filters.role_type
  if (filters.status) params['filter[status]'] = filters.status
  if (filters.company_id) params['filter[company_id]'] = filters.company_id
  if (filters.page && filters.page > 1) params.page = String(filters.page)
  return params
}

/** The global contact book (spec 07 §UI). */
export const listContacts = async (filters: ContactFilters = {}): Promise<Paginated<Contact>> => {
  const { data } = await api.get<Paginated<Contact>>('/contacts', { params: buildParams(filters) })
  return data
}

/** A company's contacts — the detail page tab. Returns the whole (scoped) list, not a page. */
export const listCompanyContacts = async (
  companyId: string,
  filters: Omit<ContactFilters, 'company_id' | 'page'> = {},
): Promise<Contact[]> => {
  const { data } = await api.get<{ data: Contact[] }>(`/companies/${companyId}/contacts`, {
    params: buildParams(filters),
  })
  return data.data
}

export const getContact = async (id: string): Promise<Contact> => {
  const { data } = await api.get<{ data: Contact }>(`/contacts/${id}`)
  return data.data
}

export const createCompanyContact = async (
  companyId: string,
  input: ContactInput,
): Promise<ContactSaveResponse> => {
  const { data } = await api.post<{ data: Contact; duplicates: DuplicateContact[] }>(
    `/companies/${companyId}/contacts`,
    input,
  )
  return { contact: data.data, duplicates: data.duplicates ?? [] }
}

export const updateContact = async (
  id: string,
  input: ContactInput,
): Promise<ContactSaveResponse> => {
  const { data } = await api.patch<{ data: Contact; duplicates: DuplicateContact[] }>(
    `/contacts/${id}`,
    input,
  )
  return { contact: data.data, duplicates: data.duplicates ?? [] }
}

export const makeContactPrimary = async (id: string): Promise<Contact> => {
  const { data } = await api.post<{ data: Contact }>(`/contacts/${id}/make-primary`)
  return data.data
}

export const deleteContact = async (id: string): Promise<void> => {
  await api.delete(`/contacts/${id}`)
}

export const restoreContact = async (id: string): Promise<Contact> => {
  const { data } = await api.post<{ data: Contact }>(`/contacts/${id}/restore`)
  return data.data
}
