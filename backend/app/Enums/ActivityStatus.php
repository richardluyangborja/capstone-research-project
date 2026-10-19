<?php

namespace App\Enums;

enum ActivityStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /**
     * The task state machine (module 04, spec 12 §S1): a task is owed until it is finished or
     * dropped, and either terminal state can be reopened. A self-move is not a transition, so
     * `open → open` is refused wherever the others are — the same shape as every other
     * lifecycle in this CRM (`LeadStatus`, `CompanyStatus`, `ContractStatus`).
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Done, self::Cancelled],
            self::Done, self::Cancelled => [self::Open],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    /**
     * The word the worklist shows for this state (spec 12 §UI).
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }
}
