import type { BillingUnit, ContractRateCard } from '../types'
import type { ContractRateCardInput } from './contracts'

/** One editable row in the replace-the-set rate-card editor (module 01 refresh S7). */
export interface RateCardForm {
  key: string
  site_id: string
  role_title: string
  headcount: string
  billing_unit: BillingUnit
  bill_rate: string
  pay_rate: string
  units_per_period: string
  notes: string
}

let rateCardKey = 0

export const newRateCardRow = (): RateCardForm => ({
  key: `rate-card-${(rateCardKey += 1)}`,
  site_id: '',
  role_title: '',
  headcount: '1',
  billing_unit: 'hourly',
  bill_rate: '',
  pay_rate: '',
  units_per_period: '176',
  notes: '',
})

export const formFromRateCard = (card: ContractRateCard): RateCardForm => ({
  key: `rate-card-${card.id}`,
  site_id: card.site_id ?? '',
  role_title: card.role_title,
  headcount: String(card.headcount),
  billing_unit: card.billing_unit,
  bill_rate: card.bill_rate,
  pay_rate: card.pay_rate ?? '',
  units_per_period: card.units_per_period ?? (card.billing_unit === 'hourly' ? '176' : '1'),
  notes: card.notes ?? '',
})

/** The client-facing monthly preview for one editor row (placement is one-time). */
export const rateCardPreview = (row: RateCardForm): number => {
  const headcount = Number(row.headcount) || 0
  const rate = Number(row.bill_rate) || 0

  if (row.billing_unit === 'placement') {
    return headcount * rate
  }

  const units = row.billing_unit === 'hourly' ? Number(row.units_per_period) || 0 : 1

  return headcount * rate * units
}

export interface RateCardTotals {
  clientMonthly: number
  placement: number
}

/** Preview totals split the recurring monthly value from the one-time placement fees. */
export const rateCardTotals = (rows: RateCardForm[]): RateCardTotals =>
  rows.reduce<RateCardTotals>(
    (totals, row) => {
      const amount = rateCardPreview(row)

      if (row.billing_unit === 'placement') {
        totals.placement += amount
      } else {
        totals.clientMonthly += amount
      }

      return totals
    },
    { clientMonthly: 0, placement: 0 },
  )

/** The wire shape of the replace-the-set endpoint; array order is display order. */
export const rateCardsToPayload = (rows: RateCardForm[]): ContractRateCardInput[] =>
  rows.map((row) => ({
    site_id: row.site_id || null,
    role_title: row.role_title.trim(),
    headcount: Math.max(1, Number(row.headcount) || 1),
    billing_unit: row.billing_unit,
    bill_rate: Number(row.bill_rate) || 0,
    pay_rate: row.pay_rate ? Number(row.pay_rate) : null,
    units_per_period: row.billing_unit === 'hourly' ? Number(row.units_per_period) || 176 : 1,
    notes: row.notes.trim() || null,
  }))
