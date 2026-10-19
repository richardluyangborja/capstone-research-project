<?php

namespace App\Http\Requests;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationOutcome;
use App\Enums\CommunicationStatus;
use App\Models\Communication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Answering a message (module 03 slice S4). A reply is another thing said about the record its
 * parent belongs to, so the record is **inherited, never accepted**: a request that names one
 * is rejected rather than quietly ignored (no message ever changes hands, spec 11 §Business
 * rules 1 and 3).
 *
 * Everything else is what the parent does not already answer: the channel defaults to the
 * parent's (an email thread stays email), the direction to outbound (the person using the app
 * is the one answering), and the title line to the parent's.
 */
class StoreReplyRequest extends FormRequest
{
    /**
     * Anyone who may log a message may answer one; the parent's record is checked in the
     * controller against its own `view`/`update` rights (module 03 §Permissions).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Communication::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['prohibited'],
            'subject_id' => ['prohibited'],
            'contact_id' => ['nullable', 'string', 'exists:contacts,id'],
            'channel' => ['nullable', Rule::enum(CommunicationChannel::class)],
            'direction' => ['nullable', Rule::enum(CommunicationDirection::class)],
            // `draft` is only offered for an outbound email (slice S2); `scheduled` belongs to
            // module 04. An unreadable channel/direction is caught by its own rule above, so
            // the fallback here only decides the message the caller sees first.
            'status' => ['nullable', Rule::in(array_map(
                static fn (CommunicationStatus $status): string => $status->value,
                $this->settableStatuses(),
            ))],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'string', 'max:255'],
            'to_name' => ['nullable', 'string', 'max:255'],
            'to_address' => ['nullable', 'string', 'max:255'],
            // A reply records something that has already happened.
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'outcome' => ['nullable', Rule::enum(CommunicationOutcome::class)],
        ];
    }

    /**
     * The statuses the reply may carry — settled against the pair it will end up with, so a
     * reply that inherits the parent's channel is judged by that channel's own rule.
     *
     * @return array<int, CommunicationStatus>
     */
    private function settableStatuses(): array
    {
        $channel = CommunicationChannel::tryFrom((string) $this->input('channel'))
            ?? $this->parent()?->channel;

        if ($channel === null) {
            return CommunicationStatus::loggable();
        }

        // Only the composer schedules a message for later (spec 12 §S2); a reply is answered in
        // the conversation it belongs to, so it may be a draft but never a scheduled send.
        return array_values(array_filter(
            CommunicationStatus::settableFor($channel, $this->replyDirection()),
            static fn (CommunicationStatus $status): bool => $status !== CommunicationStatus::Scheduled,
        ));
    }

    /**
     * The direction the reply will end up with — its own, or outbound when it does not pick
     * one, because the caller is the one answering.
     */
    private function replyDirection(): CommunicationDirection
    {
        return CommunicationDirection::tryFrom((string) $this->input('direction'))
            ?? CommunicationDirection::Outbound;
    }

    /**
     * The message being answered — the route binds it, so the request can judge a payload
     * against the record and channel it is about to inherit.
     */
    private function parent(): ?Communication
    {
        $parent = $this->route('communication');

        return $parent instanceof Communication ? $parent : null;
    }
}
