<?php

namespace App\Enums;

/**
 * The account lifecycle state (spec 19 S2, ADR-0030). Accounts are suspended and restored,
 * never deleted — deletion is explicitly out of the first administration module.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function isSuspended(): bool
    {
        return $this === self::Suspended;
    }
}
