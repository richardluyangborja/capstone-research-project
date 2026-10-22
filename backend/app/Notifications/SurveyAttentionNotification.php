<?php

namespace App\Notifications;

use App\Models\SurveyResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An unhappy survey answer is somebody's job (module 05 slice S4, spec 13 §S4). Database-only,
 * like every bell notification in this phase, and it carries its own `url` so the bell opens the
 * answer rather than guessing from a record id.
 */
class SurveyAttentionNotification extends Notification
{
    use Queueable;

    public function __construct(public SurveyResponse $response) {}

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
        $company = $this->response->company?->name ?? 'a client';

        return [
            'type' => 'survey_attention',
            'survey_response_id' => $this->response->id,
            'company_id' => $this->response->company_id,
            'company_name' => $this->response->company?->name,
            'url' => '/satisfaction/responses/'.$this->response->id,
            'message' => sprintf(
                'Unhappy feedback from %s: %s.',
                $company,
                $this->response->scoreLine(),
            ),
        ];
    }
}
