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
     * The client lifecycle (spec 09 §1). `prospect → active` is legal because the first
     * signed contract may activate a company straight out of the prospect state.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Prospect => [self::Onboarding, self::Active],
            self::Onboarding => [self::Active, self::Churned],
            self::Active => [self::AtRisk, self::Dormant, self::Churned],
            self::AtRisk => [self::Active, self::Dormant, self::Churned],
            self::Dormant => [self::Active, self::AtRisk, self::Churned],
            self::Churned => [self::Active],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * `Client` is not a table — a company is a client once it moves past `prospect`
     * (spec 00 §Entity relationships).
     */
    public function isClient(): bool
    {
        return $this !== self::Prospect;
    }

    public function isChurned(): bool
    {
        return $this === self::Churned;
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * A churned client needs a recorded reason (spec 09 §5).
     */
    public function requiresReason(): bool
    {
        return $this === self::Churned;
    }
}
