import type { ReactNode } from 'react'
import DataTable, { type DataTableColumn } from './DataTable'
import { billingUnitLabels } from '../../lib/contractMeta'
import { formatCurrency } from '../../lib/format'
import type { ContractRateCard } from '../../types'

interface RateCardTableProps {
  rateCards: ContractRateCard[]
  /** Pay rate and internal cost columns are manager+ (ADR-0024 §5). */
  canViewMargin: boolean
  empty?: ReactNode
}

/** The read-only rate-card table shared by Account 360 and the contract detail. */
const rateCardColumns = (canViewMargin: boolean): DataTableColumn<ContractRateCard>[] => {
  const columns: DataTableColumn<ContractRateCard>[] = [
    {
      key: 'role',
      header: 'Role',
      render: (card) => (
        <div className="flex flex-col">
          <span className="font-medium text-foreground">{card.role_title}</span>
          {card.notes && <span className="text-xs text-muted-foreground">{card.notes}</span>}
        </div>
      ),
    },
    {
      key: 'site',
      header: 'Site',
      className: 'text-muted-foreground',
      render: (card) => card.site?.label ?? '—',
    },
    {
      key: 'unit',
      header: 'Unit',
      className: 'text-muted-foreground',
      render: (card) => billingUnitLabels[card.billing_unit],
    },
    {
      key: 'headcount',
      header: 'Heads',
      className: 'text-right text-muted-foreground',
      headerClassName: 'text-right',
      render: (card) => card.headcount,
    },
    {
      key: 'bill_rate',
      header: 'Bill rate',
      className: 'text-right',
      headerClassName: 'text-right',
      render: (card) => formatCurrency(card.bill_rate),
    },
    {
      key: 'client_monthly',
      header: 'Client / mo',
      className: 'text-right',
      headerClassName: 'text-right',
      render: (card) =>
        card.billing_unit === 'placement' ? (
          <div className="flex flex-col items-end">
            <span>—</span>
            <span className="text-xs text-muted-foreground">
              {formatCurrency(card.line_placement_fee)} once
            </span>
          </div>
        ) : (
          formatCurrency(card.line_client_monthly)
        ),
    },
  ]

  if (canViewMargin) {
    columns.push(
      {
        key: 'pay_rate',
        header: 'Pay rate',
        className: 'text-right text-muted-foreground',
        headerClassName: 'text-right',
        render: (card) => (card.pay_rate === null ? '—' : formatCurrency(card.pay_rate)),
      },
      {
        key: 'internal_monthly',
        header: 'Internal / mo',
        className: 'text-right text-muted-foreground',
        headerClassName: 'text-right',
        render: (card) =>
          card.line_internal_monthly === null ? '—' : formatCurrency(card.line_internal_monthly),
      },
    )
  }

  return columns
}

export default function RateCardTable({ rateCards, canViewMargin, empty }: RateCardTableProps) {
  return (
    <DataTable
      columns={rateCardColumns(canViewMargin)}
      rows={rateCards}
      rowKey={(card) => card.id}
      empty={
        empty ?? (
          <p className="text-sm text-muted-foreground">No rate cards on this contract yet.</p>
        )
      }
    />
  )
}
