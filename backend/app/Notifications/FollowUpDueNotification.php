<?php

namespace App\Notifications;

use App\Models\Activity;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A follow-up you owe has fallen due (module 04 slice S3, spec 12 §UI, §10). Raised by the due
 * run, once per task: the run stamps `reminded_at`, so the same task cannot be flagged twice
 * until it is rescheduled.
 */
class FollowUpDueNotification extends Notification
{
    use Queueable;

    public function __construct(public Activity $task) {}

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
        $due = $this->task->dueState();
        $record = $this->task->recordLabel();

        return [
            'type' => 'follow_up_due',
            'follow_up_id' => $this->task->id,
            'record_type' => $this->task->recordType(),
            'record_id' => $this->task->subject_id,
            'due_at' => $this->task->due_at?->toIso8601String(),
            'overdue' => $due?->isOverdue() ?? false,
            'url' => '/follow-ups/'.$this->task->id,
            'message' => sprintf(
                '%s: %s%s.',
                $due?->label() ?? 'Due',
                $this->task->subject,
                $record === null ? '' : sprintf(' (%s)', $record),
            ),
        ];
    }
}
