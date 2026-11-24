<?php

namespace App\Enums;

/**
 * A discrepancy's review state (module 09 slice T4, spec 21 §T4, ADR-0033).
 *
 * Two stored states only: a flag is open until a manager or admin settles it. "Under Review" is
 * the UI's word for `open`, not a third value — there is no second queue to fall out of sync with.
 */
enum DiscrepancyStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Under Review',
            self::Resolved => 'Resolved',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
