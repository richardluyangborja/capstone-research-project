export type Role = 'salesrep' | 'manager' | 'admin' | 'superadmin'

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
  }
  links: Record<string, string | null>
}
