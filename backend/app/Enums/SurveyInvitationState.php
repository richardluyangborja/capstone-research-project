<?php

namespace App\Enums;

/**
 * What an invitation reads as today (module 05 slice S2, spec 13 §Data model).
 *
 * The stored status says what happened; the state adds `expired` — a `sent` invitation whose
 * window has closed. It is computed when the record is read, so no scheduled job has to sweep
 * the table (ADR-0019 §3).
 */
enum SurveyInvitationState: string
{
    case Sent = 'sent';
    case Expired = 'expired';
    case Failed = 'failed';
    case Submitted = 'submitted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Awaiting a reply',
            self::Expired => 'Expired',
            self::Failed => 'Failed to send',
            self::Submitted => 'Submitted',
            self::Cancelled => 'Cancelled',
        };
    }
}
