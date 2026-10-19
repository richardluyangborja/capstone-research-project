<?php

namespace App\Console\Commands;

use App\Models\Activity;
use Illuminate\Console\Command;

/**
 * Flag the follow-ups that have come due (module 04 slice S3, spec 12 §6, §10).
 *
 * Nothing runs on a timer in this phase, so the demo (and the test suite) dispatches by hand —
 * exactly like the due sends: `php artisan follow-ups:notify-due`. A second run flags nothing,
 * because each task is stamped `reminded_at` as it is raised.
 */
class NotifyDueFollowUps extends Command
{
    protected $signature = 'follow-ups:notify-due';

    protected $description = 'Notify the owners of every open follow-up that has fallen due';

    public function handle(): int
    {
        $result = Activity::notifyDue();

        $this->info(sprintf(
            '%s follow-%s flagged; owners notified.',
            $result['notified'],
            $result['notified'] === 1 ? 'up' : 'ups',
        ));

        return self::SUCCESS;
    }
}
