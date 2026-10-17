<?php

namespace App\Enums;

/**
 * Contract status (spec 09 §1). `signed` is the only status that binds a client, so it is
 * only reachable through `POST /contracts/{id}/mark-signed` — which advances the company
 * and fires the mocked service handoff.
 */
enum ContractStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Signed = 'signed';
    case Expired = 'expired';
    case Terminated = 'terminated';

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::Signed, self::Terminated],
            self::Sent => [self::Draft, self::Signed, self::Terminated],
            self::Signed => [self::Expired, self::Terminated],
            self::Expired => [self::Terminated],
            self::Terminated => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * Transitions a plain `PATCH` may perform. Signing is absent on purpose: it has
     * side effects (client activation + service handoff) and has its own action.
     *
     * @return array<int, self>
     */
    public function accessibleTransitions(): array
    {
        return array_values(array_filter(
            $this->allowedTransitions(),
            fn (self $status): bool => $status !== self::Signed,
        ));
    }

    /**
     * A signed contract is the active engagement: it blocks deleting the company.
     */
    public function isActive(): bool
    {
        return $this === self::Signed;
    }

    public function isTerminal(): bool
    {
        return $this === self::Expired || $this === self::Terminated;
    }
}
