import { api } from './api'
import type { BillingBook, BillingBookFilters } from '../types'

/**
 * The scoped cross-account AR book (module 01 refresh S12). Read-only like the account
 * Finance feed: Finance owns the invoices, so there is no write helper.
 */
export const listBilling = async (filters: BillingBookFilters = {}): Promise<BillingBook> => {
  const params: Record<string, string | number> = {}
  if (filters.search) params.search = filters.search
  if (filters.status) params['filter[status]'] = filters.status
  if (filters.overdue !== undefined) params['filter[overdue]'] = filters.overdue ? '1' : '0'
  if (filters.aging) params['filter[aging]'] = filters.aging
  if (filters.company) params['filter[company]'] = filters.company
  if (filters.owner) params['filter[owner]'] = filters.owner
  if (filters.page) params.page = filters.page
  if (filters.per_page) params.per_page = filters.per_page

  const { data } = await api.get<BillingBook>('/billing', { params })
  return data
}
