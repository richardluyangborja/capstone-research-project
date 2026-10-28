import { api } from './api'
import type {
  BillingUnit,
  CompanyContracts,
  Contract,
  ContractInput,
  ContractRateCard,
  ContractStatus,
} from '../types'

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
    ['payment_terms', input.payment_terms],
    ['po_reference', input.po_reference],
    ['auto_renew', input.auto_renew ? '1' : undefined],
    ['notice_days', input.notice_days ?? undefined],
    ['signatory_name', input.signatory_name],
    ['signatory_position', input.signatory_position],
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
  payment_terms?: string | null
  po_reference?: string | null
  auto_renew?: boolean
  notice_days?: number | null
  signatory_name?: string | null
  signatory_position?: string | null
  terms?: string | null
  notes?: string | null
  status?: ContractStatus
}

export const updateContract = async (id: string, input: ContractUpdate): Promise<Contract> => {
  const { data } = await api.patch<{ data: Contract }>(`/contracts/${id}`, input)
  return data.data
}

/** One line in the replace-the-set payload (array order is display order). */
export interface ContractRateCardInput {
  site_id?: string | null
  role_title: string
  headcount: number
  billing_unit: BillingUnit
  bill_rate: number
  pay_rate?: number | null
  units_per_period?: number | null
  notes?: string | null
}

export const listContractRateCards = async (contractId: string): Promise<ContractRateCard[]> => {
  const { data } = await api.get<{ data: ContractRateCard[] }>(
    `/contracts/${contractId}/rate-cards`,
  )
  return data.data
}

/**
 * Replaces the contract's rate-card set (manager+). The response is the full contract with
 * the computed totals, so the caller can refresh its row without a second read.
 */
export const replaceContractRateCards = async (
  contractId: string,
  rateCards: ContractRateCardInput[],
): Promise<Contract> => {
  const { data } = await api.put<{ data: Contract }>(`/contracts/${contractId}/rate-cards`, {
    rate_cards: rateCards,
  })
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
