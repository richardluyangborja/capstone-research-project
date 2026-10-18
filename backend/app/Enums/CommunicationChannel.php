<?php

namespace App\Enums;

/**
 * How a communication happened (spec 11 §Channels, ADR-0017 §3).
 */
enum CommunicationChannel: string
{
    case Email = 'email';
    case Call = 'call';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Meeting = 'meeting';
    case Letter = 'letter';
    case Other = 'other';

    /**
     * Channels that travel through a gateway and therefore have a `sent`/`received`
     * delivery state. A call, meeting or letter is recorded as it happened.
     */
    public function isElectronic(): bool
    {
        return in_array($this, [self::Email, self::Sms, self::Whatsapp], true);
    }

    /**
     * The `ActivityType` the timeline mirror uses (spec 11 §Channels) — a WhatsApp or a
     * letter is a note on the timeline until a channel-specific type is warranted.
     */
    public function activityType(): ActivityType
    {
        return match ($this) {
            self::Email => ActivityType::Email,
            self::Call => ActivityType::Call,
            self::Meeting => ActivityType::Meeting,
            default => ActivityType::Note,
        };
    }
}
