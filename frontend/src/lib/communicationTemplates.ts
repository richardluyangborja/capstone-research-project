import { api } from './api'
import type {
  CommunicationMergeField,
  CommunicationTemplate,
  CommunicationTemplateInput,
  CommunicationTemplateRender,
  CommunicationTemplateRenderInput,
  CommunicationTemplatesPage,
} from '../types'

/**
 * How the template book narrows (slice S5). `state` is one choice rather than two knobs: a
 * template is either in the composer's picker, kept but hidden from it, or retired.
 */
export interface CommunicationTemplateFilters {
  search?: string
  channel?: string
  state?: TemplateState
  page?: number
}

export type TemplateState = '' | 'active' | 'inactive' | 'retired'

const buildParams = (filters: CommunicationTemplateFilters): Record<string, string> => {
  const params: Record<string, string> = {}
  if (filters.search) params.search = filters.search
  if (filters.channel) params['filter[channel]'] = filters.channel
  if (filters.state === 'active') params['filter[active]'] = 'true'
  if (filters.state === 'inactive') params['filter[active]'] = 'false'
  if (filters.state === 'retired') params['filter[retired]'] = 'true'
  if (filters.page && filters.page > 1) params.page = String(filters.page)
  return params
}

/** The template book (module 03 §UI, slice S5) — active first, then by name. */
export const listCommunicationTemplates = async (
  filters: CommunicationTemplateFilters = {},
): Promise<CommunicationTemplatesPage> => {
  const { data } = await api.get<CommunicationTemplatesPage>('/communication-templates', {
    params: buildParams(filters),
  })
  return data
}

export const getCommunicationTemplate = async (id: string): Promise<CommunicationTemplate> => {
  const { data } = await api.get<{ data: CommunicationTemplate }>(`/communication-templates/${id}`)
  return data.data
}

/** Author one — manager+ only (the API answers 403 for a salesrep). */
export const createCommunicationTemplate = async (
  input: CommunicationTemplateInput,
): Promise<CommunicationTemplate> => {
  const { data } = await api.post<{ data: CommunicationTemplate }>(
    '/communication-templates',
    input,
  )
  return data.data
}

export const updateCommunicationTemplate = async (
  id: string,
  input: Partial<CommunicationTemplateInput>,
): Promise<CommunicationTemplate> => {
  const { data } = await api.patch<{ data: CommunicationTemplate }>(
    `/communication-templates/${id}`,
    input,
  )
  return data.data
}

/** Retire one (soft) — it leaves the picker and the book's default view keeps it. */
export const deleteCommunicationTemplate = async (id: string): Promise<void> => {
  await api.delete(`/communication-templates/${id}`)
}

export const restoreCommunicationTemplate = async (id: string): Promise<CommunicationTemplate> => {
  const { data } = await api.post<{ data: CommunicationTemplate }>(
    `/communication-templates/${id}/restore`,
  )
  return data.data
}

/**
 * The merge-field vocabulary (slice S5). Served by the API so the composer's chips and the
 * renderer cannot drift apart — the server offers only tokens it can actually fill.
 */
export const listCommunicationMergeFields = async (): Promise<CommunicationMergeField[]> => {
  const { data } = await api.get<{ data: CommunicationMergeField[] }>(
    '/communication-templates/fields',
  )
  return data.data
}

/**
 * Render a template against a record — the composer's prefill (slice S5). It reads the record's
 * own details, so the API needs the caller to be able to see it (403 otherwise).
 */
export const renderCommunicationTemplate = async (
  id: string,
  input: CommunicationTemplateRenderInput,
): Promise<CommunicationTemplateRender> => {
  const { data } = await api.post<{ data: CommunicationTemplateRender }>(
    `/communication-templates/${id}/render`,
    input,
  )
  return data.data
}
