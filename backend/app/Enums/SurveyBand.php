<?php

namespace App\Enums;

/**
 * The promoter split every satisfaction view is built on (module 05, spec 13 §Data model).
 *
 * The band is the NPS answer's own meaning: 9–10 promoter, 7–8 passive, 0–6 detractor. A
 * response with no promoter question has no band, which is why `fromNps()` takes a nullable.
 */
enum SurveyBand: string
{
    case Promoter = 'promoter';
    case Passive = 'passive';
    case Detractor = 'detractor';

    public static function fromNps(?int $nps): ?self
    {
        return match (true) {
            $nps === null => null,
            $nps >= 9 => self::Promoter,
            $nps >= 7 => self::Passive,
            default => self::Detractor,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Promoter => 'Promoter',
            self::Passive => 'Passive',
            self::Detractor => 'Detractor',
        };
    }
}
