<?php

namespace App\Enums;

enum DisqualificationReason: string
{
    case NoBudget = 'no_budget';
    case NoNeed = 'no_need';
    case Competitor = 'competitor';
    case Unresponsive = 'unresponsive';
    case BadFit = 'bad_fit';
    case Duplicate = 'duplicate';
    case Other = 'other';
}
