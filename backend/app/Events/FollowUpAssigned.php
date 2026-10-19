<?php

namespace App\Events;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Work was handed to someone (module 04 slice S3, spec 12 §Events): a follow-up task was
 * created for another person, or reassigned to them. The listener notifies the new owner —
 * unless they assigned it to themselves, which nobody needs telling about (rule 10).
 *
 * Only a task is assignable: a scheduled message belongs to whoever wrote it.
 */
class FollowUpAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Activity $task,
        public User $newOwner,
        public ?User $actor = null,
    ) {}
}
