import { api } from './api'
import type { CompanyContracts, Contract, ContractInput, ContractStatus } from '../types'

export interface ContractFilters {
  status?: string
  /** Only signed contracts inside the renewal window (config `crm.contracts.expiring_soon_days`). */
  expiring?: boolean
}

/**
 * Contracts on one company — the Contracts tab (spec 09). Creation is a multipart upload
 * because the signed document is part of the record.
 */
export const listCompanyContracts = async (
  companyId: string,
  filters: ContractFilters = {},
): Promise<CompanyContracts> => {
  const params: Record<string, string> = {}
  if (filters.status) params['filter[status]'] = filters.status
  if (filters.expiring) params['filter[expiring]'] = 'true'

  const { data } = await api.get<CompanyContracts>(`/companies/${companyId}/contracts`, { params })
  return data
}

export const createContract = async (
  companyId: string,
  input: ContractInput,
): Promise<Contract> => {
  const form = new FormData()
  form.append('file', input.file)
  form.append('title', input.title)

  const optional: [string, string | number | null | undefined][] = [
    ['opportunity_id', input.opportunity_id],
    ['renewal_of_contract_id', input.renewal_of_contract_id],
    ['start_date', input.start_date],
    ['end_date', input.end_date],
    ['value', input.value ?? undefined],
    ['terms', input.terms],
    ['notes', input.notes],
    ['status', input.status],
  ]

  optional.forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      form.append(key, String(value))
    }
  })

  const { data } = await api.post<{ data: Contract }>(`/companies/${companyId}/contracts`, form)
  return data.data
}

export interface ContractUpdate {
  title?: string
  start_date?: string | null
  end_date?: string | null
  value?: number | null
  terms?: string | null
  notes?: string | null
  status?: ContractStatus
}

export const updateContract = async (id: string, input: ContractUpdate): Promise<Contract> => {
  const { data } = await api.patch<{ data: Contract }>(`/contracts/${id}`, input)
  return data.data
}

/** The only path to `signed` — it activates the client and fires the Service handoff. */
export const markContractSigned = async (id: string): Promise<Contract> => {
  const { data } = await api.post<{ data: Contract }>(`/contracts/${id}/mark-signed`)
  return data.data
}

export const deleteContract = async (id: string): Promise<void> => {
  await api.delete(`/contracts/${id}`)
}

export const restoreContract = async (id: string): Promise<Contract> => {
  const { data } = await api.post<{ data: Contract }>(`/contracts/${id}/restore`)
  return data.data
}

/**
 * The document is streamed through an authenticated endpoint (the storage path is never
 * exposed), so the download is fetched as a blob and saved by the browser.
 */
export const downloadContractFile = async (contract: Contract): Promise<void> => {
  const { data } = await api.get<Blob>(`/contracts/${contract.id}/file`, { responseType: 'blob' })

  const url = URL.createObjectURL(data)
  const link = document.createElement('a')
  link.href = url
  link.download = contract.file.name ?? `${contract.reference}.pdf`
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
