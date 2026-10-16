<?php

namespace App\Enums;

enum LeadSource: string
{
    case WalkIn = 'walk_in';
    case Email = 'email';
    case Phone = 'phone';
    case Referral = 'referral';
    case Website = 'website';
    case Outbound = 'outbound';
    case Event = 'event';
    case Partner = 'partner';
    case Other = 'other';
}
