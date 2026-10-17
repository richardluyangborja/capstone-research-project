<?php

namespace App\Enums;

/**
 * Account tier. Manual in Phase 1 — automated scoring is deferred (spec 06 §5).
 */
enum CompanyTier: string
{
    case A = 'a';
    case B = 'b';
    case C = 'c';
}
