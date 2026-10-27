<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Someone handed you an account (module 01 refresh S5, spec 16 §Account delegation). Database-only,
 * like the lead-assignment notification: the topbar bell is the one channel this phase has.
 */
class CompanyAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Company $company,
        public ?User $assignedBy = null,
    ) {}

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
        return [
            'type' => 'company_assigned',
            'url' => '/accounts/'.$this->company->id,
            'company_id' => $this->company->id,
            'reference' => $this->company->reference,
            'company_name' => $this->company->name,
            'assigned_by' => $this->assignedBy?->name,
            'message' => sprintf(
                '%s assigned you %s (%s).',
                $this->assignedBy?->name ?? 'The system',
                $this->company->name,
                $this->company->reference,
            ),
        ];
    }
}
