<?php

namespace App\Enums;

/**
 * What the CRM has done with a survey invitation (module 05 slice S2, spec 13 §Data model).
 *
 * Only these four are stored. `expired` is **derived on read** from `expires_at` — nothing runs
 * on a timer in this phase, the same stance task due states take (ADR-0018 §5). A submitted or
 * cancelled invitation has a dead token; a failed one never reached the client and is retryable.
 */
enum SurveyInvitationStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
    case Submitted = 'submitted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Submitted => 'Submitted',
            self::Cancelled => 'Cancelled',
        };
    }
}
