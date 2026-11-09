import { useCallback, useEffect, useMemo, useState } from 'react'
import { RefreshCw } from 'lucide-react'
import PageHeader from '../../components/common/PageHeader'
import AlertItem from '../../components/common/AlertItem'
import StatusBadge from '../../components/common/StatusBadge'
import FormCard from '../../components/common/FormCard'
import { getAdminSettings, refreshAdminSettings, updateAdminSettings } from '../../lib/admin'
import { useAuth } from '../../lib/auth-context'
import { parseValidationError } from '../../lib/leads'
import type { AdminSetting, AdminSettingCategory, SettingSource } from '../../types'

const inputClass =
  'w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

/** The backend's error keys are `settings.{key}`; the rows need the bare key back. */
const errorKeyFor = (key: string): string => `settings.${key}`

const sourceBadge = (source: SettingSource) =>
  source === 'database'
    ? { tone: 'counts' as const, label: 'Database' }
    : source === 'environment'
      ? { tone: 'activity' as const, label: 'Environment' }
      : { tone: 'neutral' as const, label: 'Default' }

/**
 * Organization and CRM settings (module 08 S4, spec 19).
 *
 * The page renders the server's allow-list: grouped categories, each setting with its
 * effective value and a source badge, and an inline editor only where the API says the key
 * is `editable`. Security keys are superadmin-only; the API omits the category for an
 * admin and the page filters it again so a stale payload cannot render a platform control.
 * Changes save as one batch; setting a key back to its default removes the override
 * server-side.
 */
export default function AdminSettings() {
  const { user } = useAuth()
  const [categories, setCategories] = useState<AdminSettingCategory[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  // Drafts hold the typed input values per key; a save sends only the keys that differ from
  // the effective value the server reported.
  const [drafts, setDrafts] = useState<Record<string, string | boolean>>({})
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [notice, setNotice] = useState('')
  const [refreshing, setRefreshing] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const payload = await getAdminSettings()
      setCategories(payload)
      setDrafts({})
      setFieldErrors({})
    } catch {
      setError('Could not load the settings.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  // The API already omits the Security category for an application admin. Filtering again
  // keeps a stale/mocked payload from ever rendering a platform-only control to an admin.
  const visibleCategories = useMemo(
    () =>
      categories.filter(
        (category) => category.category !== 'security' || user?.role === 'superadmin',
      ),
    [categories, user?.role],
  )

  const draftValue = (setting: AdminSetting): string | boolean => {
    if (setting.key in drafts) return drafts[setting.key]
    if (setting.type === 'bool') return Boolean(setting.value)
    return setting.value === null ? '' : String(setting.value)
  }

  const changedKeys = useMemo(
    () =>
      visibleCategories.flatMap((category) =>
        category.settings
          .filter((setting) => {
            if (!setting.editable) return false
            const draft = draftValue(setting)
            if (setting.type === 'bool') return draft !== Boolean(setting.value)
            return draft !== (setting.value === null ? '' : String(setting.value))
          })
          .map((setting) => setting.key),
      ),
    // Drafts are compared against the effective values, so both drive the changed set.
    [visibleCategories, drafts],
  )

  const save = async () => {
    setSaving(true)
    setSaveError('')
    setNotice('')
    setFieldErrors({})

    const payload: Record<string, string | number | boolean | null> = {}
    for (const category of visibleCategories) {
      for (const setting of category.settings) {
        if (!changedKeys.includes(setting.key)) continue
        const draft = drafts[setting.key]
        if (setting.type === 'bool') {
          payload[setting.key] = Boolean(draft)
        } else if (draft === '' || draft === undefined) {
          payload[setting.key] = null
        } else if (setting.type === 'int' || setting.type === 'decimal') {
          payload[setting.key] = Number(draft)
        } else {
          payload[setting.key] = String(draft)
        }
      }
    }

    try {
      const next = await updateAdminSettings(payload)
      setCategories(next)
      setDrafts({})
      setNotice('Settings saved. The new values apply from the next request.')
    } catch (caught) {
      const validation = parseValidationError(caught)
      if (validation) {
        setFieldErrors(validation.errors)
        setSaveError(validation.message)
      } else {
        setSaveError('Could not save the settings.')
      }
    } finally {
      setSaving(false)
    }
  }

  const refresh = () => {
    if (
      !window.confirm(
        'Rebuild the effective configuration snapshot now? Nothing runs on a timer in this phase — the snapshot is also rebuilt automatically after every save.',
      )
    ) {
      return
    }

    setRefreshing(true)
    setNotice('')
    setSaveError('')
    void refreshAdminSettings()
      .then(() => setNotice('The effective configuration was refreshed.'))
      .catch(() => setSaveError('Could not refresh the configuration.'))
      .finally(() => setRefreshing(false))
  }

  const renderControl = (setting: AdminSetting) => {
    const value = draftValue(setting)
    const errors = fieldErrors[errorKeyFor(setting.key)]

    let control: React.ReactNode
    if (setting.type === 'bool') {
      const checked = Boolean(value)
      control = (
        <label className="inline-flex items-center gap-2 text-sm text-foreground">
          <input
            type="checkbox"
            checked={checked}
            disabled={!setting.editable}
            onChange={(event) =>
              setDrafts((prev) => ({ ...prev, [setting.key]: event.target.checked }))
            }
            aria-label={setting.label}
          />
          {checked ? 'Enabled' : 'Disabled'}
        </label>
      )
    } else if (setting.type === 'enum') {
      control = (
        <select
          value={String(value)}
          disabled={!setting.editable}
          onChange={(event) => setDrafts((prev) => ({ ...prev, [setting.key]: event.target.value }))}
          aria-label={setting.label}
          className={inputClass}
        >
          {(setting.options ?? []).map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      )
    } else {
      const inputType =
        setting.type === 'int' || setting.type === 'decimal'
          ? 'number'
          : setting.type === 'email'
            ? 'email'
            : setting.type === 'url'
              ? 'url'
              : 'text'
      control = (
        <input
          type={inputType}
          value={String(value)}
          disabled={!setting.editable}
          step={setting.type === 'decimal' ? '0.5' : undefined}
          onChange={(event) => setDrafts((prev) => ({ ...prev, [setting.key]: event.target.value }))}
          aria-label={setting.label}
          className={inputClass}
        />
      )
    }

    const badge = sourceBadge(setting.source)

    return (
      <div className="grid grid-cols-1 gap-3 py-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] md:items-start md:gap-4">
        <div>
          <p className="text-sm font-medium text-foreground">{setting.label}</p>
          <p className="mt-0.5 font-mono text-xs text-muted-foreground">{setting.key}</p>
        </div>
        <div>
          <StatusBadge tone={badge.tone}>{badge.label}</StatusBadge>
          {errors && <span className="mt-1 block text-xs text-destructive">{errors[0]}</span>}
        </div>
        <div>
          {control}
          {!setting.editable && <p className="mt-1 text-xs text-muted-foreground">Read-only</p>}
        </div>
      </div>
    )
  }

  return (
    <div>
      <PageHeader
        title="Settings"
        description="Typed organization and CRM settings with their effective source. Secrets never enter this surface."
        actions={
          <button
            type="button"
            onClick={refresh}
            disabled={refreshing}
            className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
          >
            <RefreshCw className="h-4 w-4" aria-hidden />
            {refreshing ? 'Refreshing…' : 'Refresh configuration'}
          </button>
        }
      />

      {error && (
        <div className="mb-4">
          <AlertItem variant="danger">{error}</AlertItem>
        </div>
      )}
      {notice && (
        <div className="mb-4">
          <AlertItem variant="success">{notice}</AlertItem>
        </div>
      )}
      {saveError && (
        <div className="mb-4">
          <AlertItem variant="danger">{saveError}</AlertItem>
        </div>
      )}

      {loading ? (
        <div className="rounded-lg border border-border bg-card p-4 text-sm text-muted-foreground">
          Loading settings…
        </div>
      ) : (
        <div className="space-y-4">
          {visibleCategories.map((category) => (
            <FormCard key={category.category} title={category.label}>
              <div className="divide-y divide-border">
                {category.settings.map((setting) => (
                  <div key={setting.key}>{renderControl(setting)}</div>
                ))}
              </div>
            </FormCard>
          ))}
        </div>
      )}

      {!loading && changedKeys.length > 0 && (
        <div className="sticky bottom-4 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card px-4 py-3">
          <p className="text-sm text-foreground">
            {changedKeys.length} setting{changedKeys.length === 1 ? '' : 's'} changed — saving
            removes overrides set back to their default.
          </p>
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => setDrafts({})}
              disabled={saving}
              className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
            >
              Discard
            </button>
            <button
              type="button"
              onClick={() => void save()}
              disabled={saving}
              className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
            >
              {saving ? 'Saving…' : 'Save changes'}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
