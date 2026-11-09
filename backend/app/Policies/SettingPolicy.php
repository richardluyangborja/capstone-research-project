<?php

namespace App\Policies;

use App\Models\User;
use App\Settings\SettingRegistry;

/**
 * Settings administration (module 08 slice S4, spec 19, ADR-0030).
 *
 * Reading is admin+ — and an application admin never sees the security category at all, so
 * superadmin-only keys are hidden rather than masked. Writing is per key: `write_role`
 * `admin` covers admin and superadmin; `security.*` is platform-operator only, and an admin
 * PATCHing one is refused (the audit `security.access_denied` row documents the boundary).
 * A read-only key has no write role to grant.
 */
class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * @param  array<int, string>|string  $keys  The keys the caller intends to write; every
     *                                           one must be inside the caller's scope.
     */
    public function update(User $user, string|array $keys): bool
    {
        if (! $user->role->isAdmin()) {
            return false;
        }

        foreach ((array) $keys as $key) {
            $definition = SettingRegistry::find($key);

            if ($definition === null) {
                continue; // Unknown keys are a validation error, not a role breach.
            }

            // A plain admin never touches the security category, whether the key is
            // writable by a platform operator or merely read-only there.
            if ($definition['category'] === 'security' && ! $user->role->canOperatePlatform()) {
                return false;
            }
        }

        return true;
    }

    public function refresh(User $user): bool
    {
        return $user->role->isAdmin();
    }
}
