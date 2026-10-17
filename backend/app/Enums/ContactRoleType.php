<?php

namespace App\Enums;

/**
 * The decision-maker type of a person at a company (spec 00 — `ContactRoleType`).
 */
enum ContactRoleType: string
{
    case HiringManager = 'hiring_manager';
    case Hr = 'hr';
    case Procurement = 'procurement';
    case Finance = 'finance';
    case Executive = 'executive';
    case Other = 'other';

    /**
     * Decision-makers are highlighted in the UI (spec 07 §UI) — everything but `other`.
     */
    public function isDecisionMaker(): bool
    {
        return $this !== self::Other;
    }
}
