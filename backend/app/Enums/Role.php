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
}
