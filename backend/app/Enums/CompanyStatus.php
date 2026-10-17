<?php

namespace App\Enums;

enum CompanyStatus: string
{
    case Prospect = 'prospect';
    case Onboarding = 'onboarding';
    case Active = 'active';
    case AtRisk = 'at_risk';
    case Dormant = 'dormant';
    case Churned = 'churned';

    /**
     * `Client` is not a table — a company is a client once it moves past `prospect`
     * (spec 00 §Entity relationships).
     */
    public function isClient(): bool
    {
        return $this !== self::Prospect;
    }
}
