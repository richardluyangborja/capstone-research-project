<?php

namespace App\Console\Commands;

use App\Models\Activity;
use Illuminate\Console\Command;

/**
 * Create the reminders the records imply (module 04 slice S4, spec 12 §11).
 *
 * Nothing runs on a timer in this phase, so a manager raises them by hand — exactly like the
 * due sends and the due run: `php artisan follow-ups:generate`. A second run creates nothing,
 * because each created task holds a `source_key` the next run finds.
 */
class GenerateFollowUps extends Command
{
    protected $signature = 'follow-ups:generate';

    protected $description = 'Create the reminders the records imply (renewals, check-ins, stale leads)';

    public function handle(): int
    {
        $result = Activity::generateFollowUps();

        $this->info(sprintf(
            '%s created, %s skipped.',
            $result['created'],
            $result['skipped'],
        ));

        foreach ($result['sources'] as $source) {
            $this->line(sprintf(
                '  %s: %s created, %s skipped',
                $source['label'],
                $source['created'],
                $source['skipped'],
            ));

            foreach ($source['skips'] as $skip) {
                $this->line(sprintf('    - %s: %s', $skip['record']['label'], $skip['reason']));
            }
        }

        return self::SUCCESS;
    }
}
