<?php

namespace App\Enums;

enum AgingLevel: string
{
    case Stale = 'stale';
    case AtRisk = 'at_risk';
    case Archive = 'archive';
}
