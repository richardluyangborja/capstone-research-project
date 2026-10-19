<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * How a follow-up's due moment reads *right now* (module 04, spec 12). Derived on read from
 * the due instant and the business calendar (ADR-0007) — nothing is ever stored as "overdue",
 * the same stance lead aging and deal staleness take.
 */
enum FollowUpDueState: string
{
    case Overdue = 'overdue';
    case Today = 'today';
    case Upcoming = 'upcoming';

    /**
     * A due moment in the past is overdue even when it was earlier today: "what is late" is
     * the question the worklist is answering, so lateness outranks the day it fell in.
     *
     * Every date is worked on a copy: `Carbon` is mutable, and a caller's `due_at` attribute
     * would be rewritten under it by `->timezone()`/`->startOfDay()`.
     */
    public static function for(?CarbonInterface $due, ?CarbonInterface $now = null): ?self
    {
        if ($due === null) {
            return null;
        }

        $now ??= now();

        if ($due->lessThan($now)) {
            return self::Overdue;
        }

        $timezone = self::timezone();

        return $due->copy()->timezone($timezone)->isSameDay($now->copy()->timezone($timezone))
            ? self::Today
            : self::Upcoming;
    }

    /**
     * Whole days between now and the due moment in the business calendar: negative when it
     * has passed, 0 for today.
     */
    public static function daysUntil(CarbonInterface $due, ?CarbonInterface $now = null): int
    {
        $now ??= now();
        $timezone = self::timezone();

        return (int) $now->copy()->timezone($timezone)->startOfDay()
            ->diffInDays($due->copy()->timezone($timezone)->startOfDay(), false);
    }

    /**
     * The last instant of the business day now falls in, as a UTC instant a query can compare
     * against (timestamps are stored UTC).
     */
    public static function endOfBusinessDay(?CarbonInterface $now = null): CarbonInterface
    {
        return ($now ?? now())->copy()->timezone(self::timezone())->endOfDay()->utc();
    }

    public function label(): string
    {
        return match ($this) {
            self::Overdue => 'Overdue',
            self::Today => 'Due today',
            self::Upcoming => 'Upcoming',
        };
    }

    /**
     * The `due` block the API returns for a task or a scheduled message, or null when the
     * record carries no due time at all.
     *
     * @return array{state: string, label: string, days: int, overdue: bool}|null
     */
    public static function block(?CarbonInterface $due, ?CarbonInterface $now = null): ?array
    {
        $state = self::for($due, $now);

        if ($state === null || $due === null) {
            return null;
        }

        return [
            'state' => $state->value,
            'label' => $state->label(),
            'days' => self::daysUntil($due, $now),
            'overdue' => $state->isOverdue(),
        ];
    }

    public function isOverdue(): bool
    {
        return $this === self::Overdue;
    }

    public static function timezone(): string
    {
        return (string) config('crm.timezone', 'Asia/Manila');
    }
}
