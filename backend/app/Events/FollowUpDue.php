<?php

namespace App\Events;

use App\Models\Activity;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A task has fallen due and its owner has not been told yet (module 04 slice S3, spec 12 §10).
 * Raised by the due run — `Activity::notifyDue()`, which the endpoint and the artisan twin both
 * call — and the listener notifies the owner. The run stamps `reminded_at` as it fires this, so
 * a second run of the same day raises nothing.
 */
class FollowUpDue
{
    use Dispatchable, SerializesModels;

    public function __construct(public Activity $task) {}
}
