import type { DiscrepancyCategory } from './employees'

/**
 * The words the UI uses for a discrepancy (module 09 slice T4, spec 21 §T4, ADR-0033).
 *
 * Labels live beside the other domain vocabulary files (`companyMeta`, `followUpMeta`, …) so the
 * employees API module stays a transport, and a page test can mock the API without the labels.
 */
export const discrepancyCategoryLabels: Record<DiscrepancyCategory, string> = {
  attendance: 'Attendance record',
  leave_balance: 'Leave balance',
  commission_calculation: 'Commission calculation',
  missing_deal: 'Missing deal',
}
