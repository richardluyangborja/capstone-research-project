<?php

namespace App\Enums;

/**
 * What a rep disputes (module 09 slice T4, spec 21 §T4, ADR-0033).
 *
 * The category also tells the profile which tab carries the **Under Review** pill: the two
 * attendance categories badge the Timekeeping tab, the two commercial ones the Compensation tab.
 */
enum DiscrepancyCategory: string
{
    case Attendance = 'attendance';
    case LeaveBalance = 'leave_balance';
    case CommissionCalculation = 'commission_calculation';
    case MissingDeal = 'missing_deal';

    public function label(): string
    {
        return match ($this) {
            self::Attendance => 'Attendance record',
            self::LeaveBalance => 'Leave balance',
            self::CommissionCalculation => 'Commission calculation',
            self::MissingDeal => 'Missing deal',
        };
    }

    /**
     * The profile section this category badges — `attendance` or `compensation`.
     */
    public function section(): string
    {
        return match ($this) {
            self::Attendance, self::LeaveBalance => 'attendance',
            self::CommissionCalculation, self::MissingDeal => 'compensation',
        };
    }

    /**
     * Whether the flag can name a CRM record. Attendance and leave live only in Core 2, so they
     * have no opportunity/contract to hang a timeline entry on (ADR-0033 §5).
     */
    public function canReferenceRecord(): bool
    {
        return $this === self::CommissionCalculation || $this === self::MissingDeal;
    }
}
