<?php

namespace App\Policies;

use App\Http\Audit\AuditPresenter;
use App\Models\AuditLog;
use App\Models\User;

/**
 * The audit book's read boundary (module 08 slice S5, spec 19 §Endpoint and policy inventory).
 *
 * Admin+ may read the book and export it. An application admin never reads a security-sensitive
 * event — those rows are superadmin-only. There is no update or delete ability: the model is
 * append-only and the routes do not exist.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function view(User $user, AuditLog $log): bool
    {
        if (! $user->role->isAdmin()) {
            return false;
        }

        return ! AuditPresenter::isSecuritySensitive($log->action)
            || $user->role->canOperatePlatform();
    }

    public function export(User $user): bool
    {
        return $user->role->isAdmin();
    }
}
