import { api } from './api'
import type { CompanyDelivery } from '../types'

/**
 * Read-only Recruitment / Deployment / Timekeeping feed for one account (module 01
 * refresh S9, spec 16 §Read-only external feeds). There is no write counterpart.
 */
export const getCompanyDelivery = async (id: string): Promise<CompanyDelivery> => {
  const { data } = await api.get<CompanyDelivery>(`/companies/${id}/delivery`)
  return data
}
