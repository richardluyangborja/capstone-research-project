<?php

namespace App\Enums;

/**
 * Why a deal was lost (spec 00 — required when `stage = lost`).
 */
enum LostReason: string
{
    case Price = 'price';
    case Competitor = 'competitor';
    case NoBudget = 'no_budget';
    case Timing = 'timing';
    case NoDecision = 'no_decision';
    case Other = 'other';
}
