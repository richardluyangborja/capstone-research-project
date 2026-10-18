<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Communication;
use App\Models\User;

/**
 * Communications inherit the visibility of the record they belong to (module 03 §Permissions,
 * ADR-0017 §5): a message about a lead is visible to whoever may see that lead. On top of
 * that, only a `draft`/`logged` record may be edited (ADR-0017 §6), and deletes are scoped
 * to the author's own record, the team's for a manager, or any for an admin.
 */
class CommunicationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Communication $communication): bool
    {
        return $this->maySeeSubject($user, $communication);
    }

    /**
     * Anyone authenticated may log a message; the *subject's* update right is checked when
     * the record is created (`StoreCommunicationRequest`).
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Correcting a logged record: its author, or a manager+ within its scope. Whether the
     * record is still editable at all is a state question the controller answers with a 422 —
     * a delivered message is a record of what was said (ADR-0017 §6).
     */
    public function update(User $user, Communication $communication): bool
    {
        return $this->ownsOrOversees($user, $communication)
            && $this->maySeeSubject($user, $communication);
    }

    public function delete(User $user, Communication $communication): bool
    {
        return $this->ownsOrOversees($user, $communication)
            && $this->maySeeSubject($user, $communication);
    }

    /**
     * Restoring a retired history entry is structural — manager+ (module 03 permissions).
     */
    public function restore(User $user, Communication $communication): bool
    {
        return $user->role !== Role::Salesrep
            && $this->ownsOrOversees($user, $communication)
            && $this->maySeeSubject($user, $communication);
    }

    public function forceDelete(User $user, Communication $communication): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Author of the record: salesrep own, manager team, admin all (ADR-0004).
     */
    private function ownsOrOversees(User $user, Communication $communication): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        if ($communication->owner_id === $user->id) {
            return true;
        }

        return $user->role === Role::Manager
            && $user->team_id !== null
            && $communication->owner?->team_id === $user->team_id;
    }

    /**
     * The message is visible only while the record it belongs to is.
     */
    private function maySeeSubject(User $user, Communication $communication): bool
    {
        $subject = $communication->subjectable;

        return $subject !== null && $user->can('view', $subject);
    }
}
