<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Activity;
use App\Models\User;

/**
 * Follow-up tasks (module 04, spec 12 §Permissions). A task is an `Activity` with
 * `type = task`: it is readable with the record it hangs off (ADR-0017 §5, the same rule as a
 * message) and it owes to **one person**, so who may move it follows assignment (ADR-0004) —
 * a salesrep their own, a manager the team's, an admin anyone's.
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Activity $task): bool
    {
        return $task->isTask() && $this->maySeeSubject($user, $task);
    }

    /**
     * Anyone may owe themselves something; the *subject's* update right is checked when the
     * task is created (`StoreTaskRequest`), so `create` itself has nothing to decide.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Activity $task): bool
    {
        return $task->isTask() && $this->ownsOrOversees($user, $task);
    }

    /**
     * Completing, cancelling and reopening are the same right as editing the task.
     */
    public function transition(User $user, Activity $task): bool
    {
        return $this->update($user, $task);
    }

    /**
     * Running the due reminders notifies owners across every team at once, so it is an admin
     * action (spec 12 §Permissions) — the same reasoning as dispatching the due sends.
     */
    public function dispatchDue(User $user): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Handing a task to someone else — the lead-assignment rule (spec 03 §2): yourself, the
     * team's for a manager, anyone for an admin. Shared with the picker and both write paths so
     * the rule cannot drift.
     */
    public static function mayAssignTo(User $user, User $assignee): bool
    {
        if ($user->id === $assignee->id) {
            return true;
        }

        if ($user->role->isAdmin()) {
            return true;
        }

        return $user->role === Role::Manager
            && $user->team_id !== null
            && $assignee->team_id === $user->team_id;
    }

    /**
     * The owner of the task: salesrep own, manager team, admin all (ADR-0004).
     */
    private function ownsOrOversees(User $user, Activity $task): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        if ($task->owner_id === $user->id) {
            return true;
        }

        return $user->role === Role::Manager
            && $user->team_id !== null
            && $this->ownerTeamId($task) === $user->team_id;
    }

    /**
     * The owner's team. A payload eager-loads the owner with the few columns it shows
     * (`owner:id,name,team_id`), but the rule must not depend on that: if `team_id` was not
     * selected, it is read from the record instead of being quietly taken as null.
     */
    private function ownerTeamId(Activity $task): ?string
    {
        $owner = $task->owner;

        if ($owner === null) {
            return null;
        }

        return $owner->hasAttribute('team_id')
            ? $owner->team_id
            : User::query()->whereKey($owner->getKey())->value('team_id');
    }

    /**
     * A task is visible only while the record it hangs off is.
     */
    private function maySeeSubject(User $user, Activity $task): bool
    {
        $subject = $task->subjectable;

        return $subject !== null && $user->can('view', $subject);
    }
}
