<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Survey;
use App\Models\User;

/**
 * The survey library (module 05 slice S1, spec 13 §Permissions, ADR-0019 §8).
 *
 * A survey is **house wording**, exactly like a message template: every role reads the library,
 * only manager+ authors, edits, duplicates, retires or restores one. There is no per-record
 * scoping — a survey is not about a record. Who may *send* one to a client is the company's own
 * `update` right (slice S2).
 */
class SurveyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Survey $survey): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Survey $survey): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Survey $survey): bool
    {
        return $this->manages($user);
    }

    public function restore(User $user, Survey $survey): bool
    {
        return $this->manages($user);
    }

    public function forceDelete(User $user, Survey $survey): bool
    {
        return $user->role->isAdmin();
    }

    private function manages(User $user): bool
    {
        return $user->role !== Role::Salesrep;
    }
}
