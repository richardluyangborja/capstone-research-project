<?php

namespace App\Enums;

/**
 * The dashboard's period toggle (spec 14 §Data contract).
 *
 * `30d` is the last 30 calendar days, `quarter` the trailing 90 days (the shape the D1
 * fixtures tell: "Monthly, Jul – Oct" in late October) and `ytd` the calendar year to date.
 * The window is resolved in the business calendar and queried in UTC — see
 * `App\Http\Dashboard\DashboardPeriod`.
 */
enum DashboardRange: string
{
    case Last30Days = '30d';
    case Quarter = 'quarter';
    case YearToDate = 'ytd';

    public function label(): string
    {
        return match ($this) {
            self::Last30Days => 'Last 30 days',
            self::Quarter => 'This quarter',
            self::YearToDate => 'Year to date',
        };
    }
}
