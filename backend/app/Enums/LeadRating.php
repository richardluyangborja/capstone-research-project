<?php

namespace App\Enums;

enum LeadRating: string
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Cold = 'cold';
}
