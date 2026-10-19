<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Someone handed you follow-up work (module 04 slice S3, spec 12 §UI, §10). Database-only, like
 * the lead-assignment notification: the bell in the topbar is the one channel this phase has.
 */
class FollowUpAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Activity $task,
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
        $opening = $this->assignedBy !== null
            ? sprintf('%s asked you to %s', $this->assignedBy->name, $this->task->subject)
            : sprintf('Assigned to you: %s', $this->task->subject);

        $record = $this->task->recordLabel();

        return [
            'type' => 'follow_up_assigned',
            'follow_up_id' => $this->task->id,
            'record_type' => $this->task->recordType(),
            'record_id' => $this->task->subject_id,
            'due_at' => $this->task->due_at?->toIso8601String(),
            'url' => '/follow-ups/'.$this->task->id,
            'message' => $record === null ? $opening.'.' : sprintf('%s (%s).', $opening, $record),
        ];
    }
}
