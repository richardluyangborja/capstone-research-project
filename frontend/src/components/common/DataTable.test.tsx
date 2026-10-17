import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import DataTable, { type DataTableColumn } from './DataTable'

interface Row {
  id: string
  name: string
}

const columns: DataTableColumn<Row>[] = [{ key: 'name', header: 'Name', render: (row) => row.name }]

const rows: Row[] = [
  { id: 'a', name: 'Alpha' },
  { id: 'b', name: 'Beta' },
]

describe('DataTable', () => {
  it('renders a heading and a row per record', () => {
    render(<DataTable columns={columns} rows={rows} rowKey={(row) => row.id} />)

    expect(screen.getByRole('columnheader', { name: 'Name' })).toBeInTheDocument()
    expect(screen.getAllByRole('row')).toHaveLength(rows.length + 1)
    expect(screen.getByText('Alpha')).toBeInTheDocument()
  })

  it('shows the loading row while loading', () => {
    render(<DataTable columns={columns} rows={[]} rowKey={(row) => row.id} loading />)

    expect(screen.getByText('Loading…')).toBeInTheDocument()
  })

  it('renders the empty slot instead of the table when there are no rows', () => {
    render(
      <DataTable
        columns={columns}
        rows={[]}
        rowKey={(row) => row.id}
        empty={<p>Nothing here</p>}
      />,
    )

    expect(screen.getByText('Nothing here')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })

  it('toggles a single row and all rows through the selection handlers', () => {
    const onToggle = vi.fn()
    const onToggleAll = vi.fn()

    render(
      <DataTable
        columns={columns}
        rows={rows}
        rowKey={(row) => row.id}
        selection={{ selected: [], onToggle, onToggleAll }}
      />,
    )

    fireEvent.click(screen.getByLabelText('Select a'))
    expect(onToggle).toHaveBeenCalledWith('a')

    fireEvent.click(screen.getByLabelText('Select all'))
    expect(onToggleAll).toHaveBeenCalledWith(['a', 'b'], true)
  })
})
