import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import {
  RedirectToAccount,
  RedirectToAccounts,
  RedirectToClientAccounts,
  RedirectToLeadView,
  RedirectToNewAccount,
} from './redirects'

function LocationProbe() {
  const { pathname, search } = useLocation()

  return <p data-testid="location">{`${pathname}${search}`}</p>
}

const renderRedirect = (entry: string, path: string, element: ReactNode) =>
  render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path={path} element={element} />
        <Route path="*" element={<LocationProbe />} />
      </Routes>
    </MemoryRouter>,
  )

describe('module 01 redirects', () => {
  it('sends the company book to the account book and keeps the query string', async () => {
    renderRedirect('/companies?filter=active', '/companies', <RedirectToAccounts />)

    expect(await screen.findByTestId('location')).toHaveTextContent('/accounts?filter=active')
  })

  it('sends the company creator to the account creator', async () => {
    renderRedirect('/companies/new', '/companies/new', <RedirectToNewAccount />)

    expect(await screen.findByTestId('location')).toHaveTextContent('/accounts/new')
  })

  it('keeps the account id and its query string', async () => {
    renderRedirect('/companies/company-1?tab=contracts', '/companies/:id', <RedirectToAccount />)

    expect(await screen.findByTestId('location')).toHaveTextContent(
      '/accounts/company-1?tab=contracts',
    )
  })

  it('turns the client book into the clients view of the account book', async () => {
    renderRedirect('/clients?status=active', '/clients', <RedirectToClientAccounts />)

    expect(await screen.findByTestId('location')).toHaveTextContent(
      '/accounts?status=active&view=clients',
    )
  })

  it.each([
    ['/qualification', 'qualification'],
    ['/assignment', 'unassigned'],
    ['/lifecycle', 'aging'],
    ['/conversion', 'conversion'],
  ])('turns %s into the %s Lead Workspace view', async (entry, view) => {
    renderRedirect(entry, entry, <RedirectToLeadView view={view} />)

    expect(await screen.findByTestId('location')).toHaveTextContent(`/leads?view=${view}`)
  })

  it('keeps an existing query and replaces a stale view', async () => {
    renderRedirect(
      '/qualification?view=all&search=Sunrise',
      '/qualification',
      <RedirectToLeadView view="qualification" />,
    )

    expect(await screen.findByTestId('location')).toHaveTextContent(
      '/leads?view=qualification&search=Sunrise',
    )
  })
})
