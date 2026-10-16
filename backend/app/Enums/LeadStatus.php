<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Converted = 'converted';
    case Disqualified = 'disqualified';
    case Nurture = 'nurture';
    case Archived = 'archived';

    /**
     * Legal target states for the lifecycle state machine (spec 04 §1).
     * Any transition not listed here is rejected with a 422.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Contacted, self::Disqualified, self::Archived],
            self::Contacted => [self::Qualified, self::Disqualified, self::Nurture, self::Archived],
            self::Qualified => [self::Converted, self::Disqualified, self::Nurture],
            self::Nurture => [self::Contacted, self::Qualified, self::Archived],
            self::Disqualified => [self::Contacted, self::Archived],
            self::Converted => [],
            self::Archived => [self::New],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * Transitions the generic status endpoint may apply. Conversion is excluded because
     * lead→client conversion creates a Company/Client and is owned by submodule 08.
     *
     * @return array<int, self>
     */
    public function actionableTransitions(): array
    {
        return array_values(array_filter(
            $this->allowedTransitions(),
            fn (self $status): bool => $status !== self::Converted,
        ));
    }

    /**
     * Converted is terminal — the lead becomes read-only (spec 04 §1, §4).
     */
    public function isTerminal(): bool
    {
        return $this === self::Converted;
    }
}
