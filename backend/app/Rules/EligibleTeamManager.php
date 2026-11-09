<?php

namespace App\Rules;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A team manager must be an active account with the manager role (spec 19 §S3 acceptance).
 *
 * The payload names the account by its ULID `public_id`; the rule resolves it back to the
 * internal user before the controller stores `teams.manager_id`. Admins are deliberately not
 * eligible: a team's manager is a sales manager, not an application administrator.
 */
class EligibleTeamManager implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $manager = User::query()->where('public_id', (string) $value)->first();

        if ($manager === null) {
            $fail('The selected manager account does not exist.');

            return;
        }

        if ($manager->role !== Role::Manager) {
            $fail('The selected account must hold the manager role.');

            return;
        }

        if ($manager->status !== UserStatus::Active) {
            $fail('A suspended account cannot manage a team.');
        }
    }
}
