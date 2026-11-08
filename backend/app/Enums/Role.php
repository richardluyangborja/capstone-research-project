<?php

namespace App\Enums;

enum Role: string
{
    case Superadmin = 'superadmin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Salesrep = 'salesrep';

    public function isAdmin(): bool
    {
        return $this === self::Superadmin || $this === self::Admin;
    }

    /**
     * Security-sensitive platform operations are superadmin-only (spec 19, ADR-0030).
     */
    public function canOperatePlatform(): bool
    {
        return $this === self::Superadmin;
    }
}
