import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './api'
import { ReportCooldownError, generateManagementReport, getManagementReport } from './reports'

vi.mock('./api', () => ({ api: { get: vi.fn(), post: vi.fn() } }))

const get = vi.mocked(api.get)
const post = vi.mocked(api.post)

const wire = (overrides: Record<string, unknown> = {}) => ({
  data: {
    variant: 'client_health',
    title: 'Client health report',
    headline: '1 client needs a callback after a low score.',
    narrative: 'Two active clients, one at risk, one unresolved answer.',
    insights: [
      {
        id: 'attention',
        title: 'The call-back queue',
        body: '1 unresolved answer needs a human.',
        domain: 'people',
        href: '/satisfaction/responses?attention=true',
        link_label: 'Read the answers',
      },
    ],
    actions: [
      {
        id: 'open_clients',
        label: 'Review the client book',
        description: 'Check lifecycle and renewals.',
        href: '/clients',
      },
    ],
    generated_at: '2026-10-24T04:00:00Z',
    source: 'rules',
    model: 'Primepower rules v1',
    cached: false,
    fallback: null,
  },
  meta: {
    range: '30d',
    scope: {
      role: 'manager',
      tier: 'team',
      label: 'Business Development team',
      description: "Business Development · the team's accounts, deals and answers.",
    },
    ai_available: false,
    variants: ['executive', 'pipeline', 'client_health'],
  },
  ...overrides,
})

describe('getManagementReport', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
  })

  it('reads the one report endpoint with the period and variant and maps its links', async () => {
    get.mockResolvedValue({ data: wire() } as never)

    const result = await getManagementReport('30d', 'client_health')

    expect(get).toHaveBeenCalledWith('/reports/management', {
      params: { range: '30d', variant: 'client_health' },
    })
    expect(result.report).toMatchObject({
      variant: 'client_health',
      title: 'Client health report',
      source: 'rules',
      model: 'Primepower rules v1',
      cached: false,
      fallback: null,
    })
    expect(result.report.generated_at).toContain('12:00')
    expect(result.report.insights[0]).toMatchObject({
      id: 'attention',
      to: '/satisfaction/responses?attention=true',
      link_label: 'Read the answers',
    })
    expect(result.report.actions[0].to).toBe('/clients')
    expect(result.meta.ai_available).toBe(false)
    expect(result.meta.variants).toEqual(['executive', 'pipeline', 'client_health'])
  })

  it('keeps a fallback message and its reason intact', async () => {
    get.mockResolvedValue({
      data: wire({
        data: {
          ...wire().data,
          fallback: {
            reason: 'rate_limited',
            message: 'AI report is unavailable right now — showing the standard management report.',
          },
        },
      }),
    } as never)

    const result = await getManagementReport('quarter', 'executive')

    expect(result.report.fallback).toEqual({
      reason: 'rate_limited',
      message: 'AI report is unavailable right now — showing the standard management report.',
    })
  })

  it('rejects when the report read fails', async () => {
    get.mockRejectedValue(new Error('reports unavailable'))

    await expect(getManagementReport('ytd', 'pipeline')).rejects.toThrow('reports unavailable')
  })
})

describe('generateManagementReport', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
  })

  it('posts the generation request and maps the model provenance', async () => {
    const modelWire = wire({
      data: {
        ...wire().data,
        source: 'model',
        model: 'openai/gpt-oss-20b',
        cached: false,
        fallback: null,
      },
      meta: { ...wire().meta, ai_available: true },
    })
    post.mockResolvedValue({ data: modelWire } as never)

    const result = await generateManagementReport('30d', 'executive', true)

    expect(post).toHaveBeenCalledWith('/reports/management/generate', {
      range: '30d',
      variant: 'executive',
      refresh: true,
    })
    expect(result.report).toMatchObject({
      source: 'model',
      model: 'openai/gpt-oss-20b',
      cached: false,
      fallback: null,
    })
    expect(result.meta.ai_available).toBe(true)
  })

  it('turns a 429 into a cooldown error with the retry horizon', async () => {
    post.mockRejectedValue({
      isAxiosError: true,
      response: { status: 429, data: { retry_after_seconds: 240 } },
    })

    const call = generateManagementReport('30d', 'executive', true)

    await expect(call).rejects.toBeInstanceOf(ReportCooldownError)
    await expect(call).rejects.toMatchObject({ retryAfterSeconds: 240 })
  })

  it('rethrows any other generation failure', async () => {
    post.mockRejectedValue(new Error('ai service is down'))

    await expect(generateManagementReport('30d', 'executive')).rejects.toThrow(
      'ai service is down',
    )
  })
})
