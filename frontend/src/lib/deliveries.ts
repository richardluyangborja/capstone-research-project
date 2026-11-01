import { api } from './api'
import type { CompanyDelivery, DeploymentBook, DeploymentBookFilters } from '../types'

/**
 * Read-only Recruitment / Deployment / Timekeeping feed for one account (module 01
 * refresh S9, spec 16 §Read-only external feeds). There is no write counterpart.
 */
export const getCompanyDelivery = async (id: string): Promise<CompanyDelivery> => {
  const { data } = await api.get<CompanyDelivery>(`/companies/${id}/delivery`)
  return data
}

/**
 * The scoped cross-account deployment book (module 01 refresh S10). Read-only like the
 * account feed: Deployment and Timekeeping own the records, so there is no write helper.
 */
export const listDeployments = async (
  filters: DeploymentBookFilters = {},
): Promise<DeploymentBook> => {
  const params: Record<string, string | number> = {}
  if (filters.search) params.search = filters.search
  if (filters.status) params['filter[status]'] = filters.status
  if (filters.company) params['filter[company]'] = filters.company
  if (filters.contract) params['filter[contract]'] = filters.contract
  if (filters.owner) params['filter[owner]'] = filters.owner
  if (filters.page) params.page = filters.page
  if (filters.per_page) params.per_page = filters.per_page

  const { data } = await api.get<DeploymentBook>('/deployments', { params })
  return data
}
