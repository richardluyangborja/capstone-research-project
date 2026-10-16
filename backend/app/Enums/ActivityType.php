<?php

namespace App\Enums;

enum ActivityType: string
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case WalkIn = 'walk_in';
    case Task = 'task';
    case Note = 'note';
    case System = 'system';
}
