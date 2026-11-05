import { api } from './api'
import type {
  CompanyContracts,
  Contract,
  ContractBookFilters,
  ContractRateCard,
  ContractsResponse,
} from '../types'

export interface ContractFilters {
  status?: string
  expiring?: boolean
}

/** Contracts are a read-only projection owned by Contract Management. */
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

export const listContracts = async (
  filters: ContractBookFilters = {},
): Promise<ContractsResponse> => {
  const params: Record<string, string | number> = {}
  if (filters.search) params.search = filters.search
  if (filters.status) params['filter[status]'] = filters.status
  if (filters.expiring) params['filter[expiring]'] = 'true'
  if (filters.renewal !== undefined) params['filter[renewal]'] = filters.renewal ? '1' : '0'
  if (filters.owner) params['filter[owner]'] = filters.owner
  if (filters.company) params['filter[company]'] = filters.company
  if (filters.page) params.page = filters.page
  if (filters.per_page) params.per_page = filters.per_page
  const { data } = await api.get<ContractsResponse>('/contracts', { params })
  return data
}

export const getContract = async (id: string): Promise<Contract> => {
  const { data } = await api.get<{ data: Contract }>(`/contracts/${id}`)
  return data.data
}

export const listContractRateCards = async (contractId: string): Promise<ContractRateCard[]> => {
  const { data } = await api.get<{ data: ContractRateCard[] }>(
    `/contracts/${contractId}/rate-cards`,
  )
  return data.data
}
