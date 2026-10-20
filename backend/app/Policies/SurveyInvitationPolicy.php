<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\SurveyInvitation;
use App\Models\User;

/**
 * Who may read or withdraw a survey invitation (module 05 slice S2, spec 13 §Permissions).
 *
 * An invitation has no audience of its own: it is visible exactly when its account is
 * (ADR-0004, ADR-0010 §6). Withdrawing or resending is the sender's own work — a salesrep may
 * only touch what they sent, a manager the team's, an admin any — the same shape a task's
 * assignment takes (ADR-0018 §4).
 */
class SurveyInvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SurveyInvitation $invitation): bool
    {
        return $invitation->company !== null && $user->can('view', $invitation->company);
    }

    public function cancel(User $user, SurveyInvitation $invitation): bool
    {
        return $this->ownsOrOversees($user, $invitation);
    }

    public function resend(User $user, SurveyInvitation $invitation): bool
    {
        return $this->ownsOrOversees($user, $invitation);
    }

    private function ownsOrOversees(User $user, SurveyInvitation $invitation): bool
    {
        if ($invitation->company === null || ! $user->can('view', $invitation->company)) {
            return false;
        }

        if ($invitation->created_by === $user->id) {
            return true;
        }

        if ($user->role->isAdmin()) {
            return true;
        }

        return $user->role === Role::Manager && $user->team_id !== null;
    }
}
