<?php

namespace App\Notifications;

use App\Models\Communication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A message that was meant to go out on its own did not (module 04 slice S3, spec 12 §10). The
 * author is told when nobody was watching the attempt — a scheduled send, or a retry someone
 * else pressed; a rep who just pressed **Retry** is already looking at the reason.
 */
class ScheduledSendFailedNotification extends Notification
{
    use Queueable;

    public function __construct(public Communication $communication) {}

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
            'type' => 'scheduled_send_failed',
            'communication_id' => $this->communication->id,
            'reference' => $this->communication->reference,
            'record_type' => $this->communication->recordType(),
            'record_id' => $this->communication->subject_id,
            'url' => '/communications/'.$this->communication->id,
            'message' => sprintf(
                'Your scheduled email "%s" could not be sent: %s',
                $this->communication->subject,
                $this->communication->failed_reason ?? 'the gateway rejected it',
            ),
        ];
    }
}
