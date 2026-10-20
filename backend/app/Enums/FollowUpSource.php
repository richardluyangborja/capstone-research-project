<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * What a generated follow-up came from (module 04 slice S4, spec 12 §11): the three things the
 * records imply on their own — a contract inside its renewal window, a client nobody has
 * contacted for a while, and a lead that has gone stale.
 *
 * The case value is the prefix of the task's `source_key`, and that key is what makes a run
 * idempotent. A renewal is keyed by its contract — it happens once — while a check-in and a
 * stale-lead nudge are keyed by the ISO week they were raised in, so next week may raise the
 * next one even while this week's task is still open.
 */
enum FollowUpSource: string
{
    case Renewal = 'renewal';
    case CheckIn = 'check_in';
    case StaleLead = 'stale_lead';

    /** The source in one word — the badge on a generated row. */
    public function label(): string
    {
        return match ($this) {
            self::Renewal => 'Renewal',
            self::CheckIn => 'Check-in',
            self::StaleLead => 'Stale lead',
        };
    }

    /** The source as a group heading, as the generator's summary lists it. */
    public function groupLabel(): string
    {
        return match ($this) {
            self::Renewal => 'Renewals',
            self::CheckIn => 'Check-ins',
            self::StaleLead => 'Stale leads',
        };
    }

    /**
     * The `source_key` for a record, bucketed by week when the source may legitimately recur.
     */
    public function keyFor(string $recordId, ?CarbonInterface $now = null): string
    {
        return $this === self::Renewal
            ? sprintf('%s:%s', $this->value, $recordId)
            : sprintf('%s:%s:%s', $this->value, $recordId, self::weekOf($now ?? now()));
    }

    /** The source behind a task's `source_key`, or null when the task was written by hand. */
    public static function fromKey(?string $key): ?self
    {
        return $key === null ? null : self::tryFrom(explode(':', $key, 2)[0]);
    }

    /**
     * The ISO week a week-bucketed key belongs to, read in the business calendar, so the bucket
     * turns over on a Manila Monday rather than a UTC one.
     */
    private static function weekOf(CarbonInterface $now): string
    {
        $day = $now->copy()->setTimezone(config('crm.timezone'));

        return sprintf('%04d-W%02d', $day->isoWeekYear, $day->isoWeek);
    }
}
