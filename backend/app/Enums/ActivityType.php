<?php

namespace App\Enums;

enum ActivityType: string
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case WalkIn = 'walk_in';
    case Task = 'task';
    case Note = 'note';
    case System = 'system';

    /**
     * Contact-implying activity types. Logging one against a `new` lead advances it to
     * `contacted` (spec 04 §2).
     */
    public function isOutboundContact(): bool
    {
        return in_array($this, [self::Call, self::Email, self::Meeting, self::WalkIn], true);
    }
}
