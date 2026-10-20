<?php

namespace App\Policies;

use App\Models\SurveyResponse;
use App\Models\User;

/**
 * Who may read a submitted answer set (module 05 slice S3, spec 13 §Permissions).
 *
 * A response inherits the account's visibility, exactly like the invitation that produced it
 * (ADR-0004, ADR-0010 §6). There is no update or delete: what the client said is immutable
 * (spec 13 §Business rules 7) — slice S4 adds the resolution note, which is the one thing that
 * changes after submission.
 */
class SurveyResponsePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SurveyResponse $response): bool
    {
        return $response->company !== null && $user->can('view', $response->company);
    }
}
