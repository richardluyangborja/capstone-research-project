<?php

namespace App\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Effective settings resolution (module 08 slice S4, spec 19, ADR-0030).
 *
 * Precedence is override, then environment, then the registry default. Persisted overrides
 * are held in one cached snapshot (`SettingRegistry::CACHE_KEY`); `apply()` pushes them onto
 * Laravel `config()` so the **next read** by the owning modules — lead aging filters, the
 * pipeline stalled count, survey thresholds, attachment ceilings, the AI switch, the mocked
 * failure flags — sees the change without redeploying. `POST /admin/settings/refresh` is the
 * only place the snapshot is rebuilt; there is no generic cache flush (spec 19 §Maintenance).
 */
class EffectiveSettings
{
    /**
     * The persisted overrides as `key => value`. Cached as a whole; one row per key.
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        return Cache::remember(SettingRegistry::CACHE_KEY, now()->addDay(), function (): array {
            return Setting::query()->where('scope', 'system')->where(function ($query): void {
                $query->where('scope_id', 0)->orWhereNull('scope_id');
            })->pluck('value', 'key')->all();
        });
    }

    /**
     * The boot-time config values, captured the first time `apply()` runs so a later call
     * can restore a key whose override was deleted — without this, an earlier apply in the
     * same process would leave the old value behind a "default" source.
     *
     * @var array<string, mixed>
     */
    private array $bootValues = [];

    /**
     * The values this resolver last wrote for active overrides, keyed by setting key. They
     * let `apply()` tell its own write apart from a config change made elsewhere (a test, a
     * console override), so it only restores a value it actually owns.
     *
     * @var array<string, mixed>
     */
    private array $appliedValues = [];

    /**
     * Push every override onto the runtime config. Called from an api-group middleware on
     * each request, so the owning modules read the effective values from `config()` as
     * usual and need no knowledge of this service. A key without an override is restored
     * to its boot value (environment or default), so `apply()` is safe to run repeatedly.
     */
    public function apply(): void
    {
        $definitions = SettingRegistry::all();
        $overrides = $this->overrides();

        foreach ($definitions as $definition) {
            if ($definition['config'] === null) {
                continue;
            }

            $path = $definition['config'];
            $key = $definition['key'];
            $current = config($path);
            $applied = array_key_exists($key, $this->appliedValues);
            $overridden = array_key_exists($key, $overrides);

            if (! $applied) {
                // No override has been written by this process yet: the current value is the
                // environment/default baseline, even if it changed since the app booted.
                $this->bootValues[$path] = $current;
            } elseif ($current !== $this->appliedValues[$key]) {
                // Somebody else changed the config after our write; that is the new baseline.
                $this->bootValues[$path] = $current;
            } elseif (! $overridden) {
                // The override was deleted. Keep the pristine baseline and restore it below.
                continue;
            }
        }

        foreach ($definitions as $definition) {
            if ($definition['config'] === null) {
                continue;
            }

            $path = $definition['config'];
            $key = $definition['key'];

            if (array_key_exists($key, $overrides)) {
                $value = $overrides[$key];
                config([$path => $value]);
                $this->appliedValues[$key] = $value;

                continue;
            }

            if (array_key_exists($key, $this->appliedValues)) {
                if (config($path) === $this->appliedValues[$key]) {
                    config([$path => $this->bootValues[$path]]);
                }

                unset($this->appliedValues[$key]);
            }
        }
    }

    /**
     * One effective value: the override, else the environment value, else the default.
     * The registry default is the fallback of last resort for keys with no `config/` path.
     */
    public function value(array $definition): mixed
    {
        $overrides = $this->overrides();

        if (array_key_exists($definition['key'], $overrides)) {
            return $overrides[$definition['key']];
        }

        if ($definition['config'] !== null) {
            return config($definition['config'], $definition['default']);
        }

        return $definition['default'];
    }

    /**
     * Where the effective value came from — `database`, `environment` or `default`. The
     * API reports this per key; it never reports secret contents because secrets are not
     * in the allow-list at all.
     */
    public function source(array $definition): string
    {
        if (array_key_exists($definition['key'], $this->overrides())) {
            return 'database';
        }

        if (SettingRegistry::envIsSet($definition)) {
            return 'environment';
        }

        return 'default';
    }

    /**
     * Drop and rebuild the snapshot, then re-apply it to the running config. Idempotent:
     * a second refresh reads the same rows.
     */
    public function refresh(): void
    {
        Cache::forget(SettingRegistry::CACHE_KEY);
        $this->apply();
    }

    /**
     * Invalidate the snapshot so the next read rebuilds it from the table. Used after a
     * write; the next `apply()` (or `refresh()`) picks the new rows up.
     */
    public function flushSnapshot(): void
    {
        Cache::forget(SettingRegistry::CACHE_KEY);
    }
}
