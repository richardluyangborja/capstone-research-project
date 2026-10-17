import { api } from './api'
import type {
  Opportunity,
  OpportunityStageMove,
  OpportunityTimeline,
  OpportunitiesPage,
  PipelineBoard,
  CompanyOpportunities,
} from '../types'

/**
 * Deals on one company — the Opportunities tab on `/companies/:id` (spec 06 §API, built in
 * submodule 08). Still read-only here; the deal's own page holds the actions.
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

export interface OpportunityFilters {
  search?: string
  stage?: string
  owner?: string
  company?: string
  source?: string
  open?: boolean
  close_window?: string
  page?: number
}

export interface CreateOpportunityInput {
  company_id: string
  name: string
  stage?: string
  amount?: number | null
  probability?: number | null
  expected_close_date?: string | null
  next_step?: string | null
  source?: string | null
  primary_contact_id?: string | null
  owner_id?: number | null
}

const buildParams = (filters: OpportunityFilters): Record<string, string> => {
  const params: Record<string, string> = {}
  if (filters.search) params.search = filters.search
  if (filters.stage) params['filter[stage]'] = filters.stage
  if (filters.owner) params['filter[owner]'] = filters.owner
  if (filters.company) params['filter[company]'] = filters.company
  if (filters.source) params['filter[source]'] = filters.source
  if (filters.open) params['filter[open]'] = 'true'
  if (filters.close_window) params['filter[close_window]'] = filters.close_window
  if (filters.page && filters.page > 1) params.page = String(filters.page)
  return params
}

/** The deal book (module 02 §API) — paginated, role-scoped, with a summary. */
export const listOpportunities = async (
  filters: OpportunityFilters = {},
): Promise<OpportunitiesPage> => {
  const { data } = await api.get<OpportunitiesPage>('/opportunities', {
    params: buildParams(filters),
  })
  return data
}

export const getOpportunity = async (id: string): Promise<Opportunity> => {
  const { data } = await api.get<{ data: Opportunity }>(`/opportunities/${id}`)
  return data.data
}

/** A deal is created directly on a visible company, or by lead→client conversion. */
export const createOpportunity = async (input: CreateOpportunityInput): Promise<Opportunity> => {
  const { data } = await api.post<{ data: Opportunity }>('/opportunities', input)
  return data.data
}

export const updateOpportunity = async (
  id: string,
  input: Partial<CreateOpportunityInput>,
): Promise<Opportunity> => {
  const { data } = await api.patch<{ data: Opportunity }>(`/opportunities/${id}`, input)
  return data.data
}

export const deleteOpportunity = async (id: string): Promise<void> => {
  await api.delete(`/opportunities/${id}`)
}

export const restoreOpportunity = async (id: string): Promise<Opportunity> => {
  const { data } = await api.post<{ data: Opportunity }>(`/opportunities/${id}/restore`)
  return data.data
}

/**
 * Move a deal through the pipeline (any-to-any with rules — ADR-0015 §1). A `lost` move
 * must carry a reason; a `won` move records the outcome and prompts contract creation.
 */
export const moveOpportunityStage = async (
  id: string,
  input: OpportunityStageMove,
): Promise<Opportunity> => {
  const { data } = await api.post<{ data: Opportunity }>(`/opportunities/${id}/stage`, input)
  return data.data
}

export const getOpportunityTimeline = async (id: string): Promise<OpportunityTimeline> => {
  const { data } = await api.get<OpportunityTimeline>(`/opportunities/${id}/timeline`)
  return data
}

export const logOpportunityActivity = async (
  id: string,
  input: { type: string; subject: string; body?: string; occurred_at?: string },
): Promise<{ opportunity: Opportunity; activity: OpportunityTimeline['data'][number] }> => {
  const { data } = await api.post<{
    data: Opportunity
    activity: OpportunityTimeline['data'][number]
  }>(`/opportunities/${id}/activities`, input)
  return { opportunity: data.data, activity: data.activity }
}

/** The pipeline board (module 02 §1) — open columns + closed deals + forecast meta. */
export const getPipeline = async (filters: OpportunityFilters = {}): Promise<PipelineBoard> => {
  const { data } = await api.get<PipelineBoard>('/pipeline', { params: buildParams(filters) })
  return data
}
