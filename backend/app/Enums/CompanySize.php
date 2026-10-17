<?php

namespace App\Enums;

enum CompanySize: string
{
    case Micro = 'micro';
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
    case Enterprise = 'enterprise';
}
