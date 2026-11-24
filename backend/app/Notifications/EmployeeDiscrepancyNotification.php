<?php

namespace App\Notifications;

use App\Models\EmployeeDiscrepancy;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Somebody disputed a figure and it is the manager's to settle (module 09 slice T4, spec 21 §T4,
 * ADR-0033). Database-only, like every bell item in this CRM; the `url` opens the employee's
 * profile, where the flag and the Resolve panel live.
 */
class EmployeeDiscrepancyNotification extends Notification
{
    use Queueable;

    public function __construct(public EmployeeDiscrepancy $discrepancy) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $employee = $this->discrepancy->employee;
        $category = $this->discrepancy->category;
        $name = $employee?->name ?? 'An employee';

        return [
            'type' => 'employee_discrepancy',
            'url' => $employee === null ? null : '/employees/'.$employee->public_id,
            'employee_id' => $employee?->public_id,
            'discrepancy_id' => $this->discrepancy->id,
            'category' => $category->value,
            'message' => sprintf('%s raised a %s discrepancy that needs review.', $name, mb_strtolower($category->label())),
        ];
    }
}
