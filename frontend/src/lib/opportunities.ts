import { api } from './api'
import type { CompanyOpportunities } from '../types'

/**
 * Deals on one company — the Opportunities tab (spec 06 §API, built in submodule 08).
 * Read-only: conversion opens deals, the pipeline module moves them.
 */
export const listCompanyOpportunities = async (
  companyId: string,
  stages?: string,
): Promise<CompanyOpportunities> => {
  const { data } = await api.get<CompanyOpportunities>(`/companies/${companyId}/opportunities`, {
    params: stages ? { 'filter[stage]': stages } : undefined,
  })
  return data
}
