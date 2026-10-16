<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeadAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Lead $lead,
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
            'type' => 'lead_assigned',
            'lead_id' => $this->lead->id,
            'reference' => $this->lead->reference,
            'company_name' => $this->lead->company_name,
            'assigned_by' => $this->assignedBy?->name,
            'message' => sprintf(
                '%s assigned you %s (%s).',
                $this->assignedBy?->name ?? 'The system',
                $this->lead->reference,
                $this->lead->company_name,
            ),
        ];
    }
}
