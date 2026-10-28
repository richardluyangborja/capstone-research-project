<?php

namespace App\Enums;

/**
 * How a contract rate-card line is billed (spec 16 §Contract commercial model, ADR-0024).
 * Placement is a one-time fee and never contributes to the monthly run rate.
 */
enum BillingUnit: string
{
    case Hourly = 'hourly';
    case Monthly = 'monthly';
    case Placement = 'placement';

    public function contributesToMonthly(): bool
    {
        return $this !== self::Placement;
    }
}
