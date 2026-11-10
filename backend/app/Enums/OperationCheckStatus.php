<?php

namespace App\Enums;

/**
 * The four-state operational health contract (module 08 slice S6, spec 19 §Health-check
 * contract).
 *
 * The set is deliberately closed: a probe either worked (`healthy`), works with a caveat the
 * operator should read (`degraded`), could not be reached (`unavailable`), or was never set up
 * (`not_configured`). There is no "unknown" and no silent success — an absent optional
 * subsystem is reported explicitly, never as healthy.
 */
enum OperationCheckStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
    case NotConfigured = 'not_configured';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Degraded => 'Degraded',
            self::Unavailable => 'Unavailable',
            self::NotConfigured => 'Not configured',
        };
    }

    /**
     * The four statuses in display order, for the summary's counts block.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
