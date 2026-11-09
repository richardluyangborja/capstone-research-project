<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditSeverity;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Settings\EffectiveSettings;
use App\Settings\SettingRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Organization and CRM settings (module 08 slice S4, spec 19, ADR-0030).
 *
 * The surface is the registry: grouped typed reads (`GET /admin/settings`), a keyed batch
 * write (`PATCH /admin/settings`) that validates every key against the allow-list, deletes
 * the override row when the submitted value equals the default (`setting.reset`), and a
 * bounded snapshot rebuild (`POST /admin/settings/refresh`). Every mutation appends an audit
 * row with redacted before/after; no secret key exists here to leak, because unknown and
 * secret-shaped keys are simply not allow-listed and come back as validation errors.
 */
class AdminSettingController extends Controller
{
    public function __construct(private readonly EffectiveSettings $effective) {}

    /**
     * The grouped read. An application admin never receives the Security category — the
     * superadmin-only keys are omitted entirely, not masked.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Setting::class);

        return response()->json(['data' => $this->payload($request->user())]);
    }

    /**
     * Batch write: `{ settings: { key: value } }`. Unknown, secret-shaped or read-only keys
     * are validation errors; a security key from a plain admin is a policy 403 (audited).
     * Setting a key back to its default deletes the override and audits `setting.reset`;
     * any other effective change upserts and audits `setting.updated`.
     */
    public function update(Request $request): JsonResponse
    {
        $payload = $request->input('settings');

        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'settings' => ['The settings payload must be an object keyed by setting key.'],
            ]);
        }

        $keys = array_keys($payload);

        try {
            Gate::authorize('update', [Setting::class, $keys]);
        } catch (AuthorizationException $exception) {
            AuditLog::record('security.access_denied', AuditSeverity::Warning, $request->user()->id, Setting::class, null, null, [
                'action' => 'setting.updated',
                'keys' => $keys,
            ]);

            throw $exception;
        }

        $this->validatePayload($payload);

        $before = [];
        foreach ($keys as $key) {
            $definition = SettingRegistry::find($key);
            $before[$key] = $this->effective->value($definition);
        }

        $changes = [];
        foreach ($payload as $key => $value) {
            $definition = SettingRegistry::find($key);
            $submitted = $this->cast($definition, $value);

            if ($this->isDefault($definition, $submitted)) {
                // Back at the default: the override row goes away and the key returns to its
                // environment/default source. Deleting a row that never existed is a no-op.
                Setting::query()->where('key', $key)->delete();
            } else {
                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $submitted, 'updated_by' => $request->user()->id],
                );
            }

            $changes[] = ['key' => $key, 'value' => $submitted];
        }

        $this->effective->refresh();

        foreach ($changes as $change) {
            $definition = SettingRegistry::find($change['key']);

            $isReset = $this->isDefault($definition, $change['value']);
            AuditLog::record(
                $isReset ? 'setting.reset' : 'setting.updated',
                AuditSeverity::Notice,
                $request->user()->id,
                Setting::class,
                null,
                [$change['key'] => $before[$change['key']]],
                [$change['key'] => $change['value']],
            );
        }

        return response()->json(['data' => $this->payload($request->user())]);
    }

    /**
     * Rebuild the effective-configuration snapshot (spec 19 §Maintenance: a bounded,
     * allow-listed refresh — no generic cache flush). Idempotent.
     */
    public function refresh(Request $request): JsonResponse
    {
        Gate::authorize('refresh', Setting::class);

        $this->effective->refresh();

        AuditLog::record('setting.refreshed', AuditSeverity::Info, $request->user()->id, Setting::class);

        return response()->json(['data' => ['refreshed' => true]]);
    }

    /**
     * The grouped payload: category label, then each setting's type, effective value, source
     * and who may write it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function payload(User $user): array
    {
        $isPlatformOperator = $user->role->canOperatePlatform();

        $grouped = [];
        foreach (SettingRegistry::all() as $definition) {
            if ($definition['category'] === 'security' && ! $isPlatformOperator) {
                continue;
            }

            $grouped[$definition['category']][] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'type' => $definition['type'],
                'options' => $definition['options'] ?? null,
                'value' => $this->effective->value($definition),
                'source' => $this->effective->source($definition),
                'scope' => $definition['scope'],
                'write_role' => $definition['write_role'],
                'read_only' => $definition['read_only'],
                'editable' => ! $definition['read_only']
                    && Gate::forUser($user)->allows('update', [Setting::class, [$definition['key']]]),
            ];
        }

        $categories = [];
        foreach (SettingRegistry::CATEGORIES as $slug => $label) {
            if (! isset($grouped[$slug])) {
                continue;
            }

            $categories[] = [
                'category' => $slug,
                'label' => $label,
                'settings' => $grouped[$slug],
            ];
        }

        return $categories;
    }

    /**
     * Per-key validation against the registry rule, then the aging cross-field rule from
     * spec 19: `at_risk_days` must stay above `stale_days` and `archive_days` above
     * `at_risk_days`, evaluated against the values that will be effective after this write.
     */
    private function validatePayload(array $payload): void
    {
        $errors = [];

        foreach ($payload as $key => $value) {
            $definition = SettingRegistry::find($key);

            if ($definition === null) {
                $errors["settings.{$key}"] = [sprintf(
                    'The %s key is not an allowed setting.',
                    $key,
                )];

                continue;
            }

            if ($definition['read_only']) {
                $errors["settings.{$key}"] = ['This setting is read-only.'];

                continue;
            }

            // The registry key carries dots, which the validator would read as nested data —
            // so the value is validated under a plain field name and the error is reported
            // back under `settings.{key}`.
            $validator = validator(['value' => $value], ['value' => $definition['rule']], [], ['value' => $definition['label']]);

            if ($validator->fails()) {
                $errors["settings.{$key}"] = $validator->errors()->all();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $aging = ['stale_days' => null, 'at_risk_days' => null, 'archive_days' => null];
        foreach (array_keys($aging) as $suffix) {
            $definition = SettingRegistry::find("crm.aging.{$suffix}");
            $aging[$suffix] = (int) ($payload["crm.aging.{$suffix}"] ?? $this->effective->value($definition));
        }

        $errors = [];
        if ($aging['at_risk_days'] <= $aging['stale_days']) {
            $errors['settings.crm.aging.at_risk_days'] = ['The at-risk threshold must be greater than the stale threshold.'];
        }

        if ($aging['archive_days'] <= $aging['at_risk_days']) {
            $errors['settings.crm.aging.archive_days'] = ['The archive threshold must be greater than the at-risk threshold.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Type-aware default comparison. The registry default for a decimal is an integer
     * literal (`3`), while the cast value is `3.0`, so a strict comparison would keep a
     * redundant override instead of deleting it and reporting the default source.
     */
    private function isDefault(array $definition, mixed $value): bool
    {
        $default = $definition['default'];

        if ($value === null || $default === null) {
            return $value === $default;
        }

        return match ($definition['type']) {
            'bool' => $value === (bool) $default,
            'int' => (int) $value === (int) $default,
            'decimal' => (float) $value === (float) $default,
            default => (string) $value === (string) $default,
        };
    }

    /**
     * Cast the submitted scalar to the registry type, so a stored bool stays bool and a
     * stored decimal stays numeric whatever the wire format sent.
     */
    private function cast(array $definition, mixed $value): mixed
    {
        // A nullable presentation key (the logo URL) keeps its null, so clearing the field
        // sends the key back to its unset default rather than storing an empty string.
        if ($value === null && $definition['default'] === null) {
            return null;
        }

        return match ($definition['type']) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            'decimal' => (float) $value,
            default => (string) $value,
        };
    }
}
