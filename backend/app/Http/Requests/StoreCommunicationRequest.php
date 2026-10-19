<?php

namespace App\Http\Requests;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationOutcome;
use App\Enums\CommunicationStatus;
use App\Models\Communication;
use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCommunicationRequest extends FormRequest
{
    /**
     * Anyone may log a message. Which record it may be logged against — and therefore
     * whether the caller may tell its story — is checked in the controller against the
     * subject's own `update` right (module 03 §Permissions, ADR-0017 §5).
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
            'subject_type' => ['required', Rule::in(array_keys(Communication::subjectTypes()))],
            'subject_id' => ['required', 'string'],
            'contact_id' => ['nullable', 'string', 'exists:contacts,id'],
            'channel' => ['required', Rule::enum(CommunicationChannel::class)],
            'direction' => ['required', Rule::enum(CommunicationDirection::class)],
            // `draft` and `scheduled` are only offered for an outbound email; `scheduled` is
            // module 04's shape (spec 12 §S2). An unreadable channel/direction is caught by its
            // own rule above, so the fallback here only decides the message the caller sees first.
            'status' => ['nullable', Rule::in(array_map(
                static fn (CommunicationStatus $status): string => $status->value,
                $this->settableStatuses(),
            ))],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'string', 'max:255'],
            'to_name' => ['nullable', 'string', 'max:255'],
            'to_address' => ['nullable', 'string', 'max:255'],
            // A logged message records something that has already happened.
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            // A scheduled message has not happened yet; `scheduled_for` is when it will.
            'scheduled_for' => ['nullable', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'outcome' => ['nullable', Rule::enum(CommunicationOutcome::class)],
        ];
    }

    /**
     * A scheduled message is a promise to send later, so the payload has to be complete now:
     * it needs a due moment to be owed at and somewhere to go when it falls due (spec 12 §S2).
     * A draft carries neither on purpose — it is unfinished work, not a promise.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('status') !== CommunicationStatus::Scheduled->value) {
                    return;
                }

                if (! $this->filled('scheduled_for')) {
                    $validator->errors()->add('scheduled_for', 'Say when the message should go out.');
                }

                $hasAddress = $this->filled('to_address');

                $contact = $this->filled('contact_id')
                    ? Contact::query()->find($this->input('contact_id'))
                    : null;

                if (! $hasAddress && ($contact?->email ?? '') === '') {
                    $validator->errors()->add(
                        'to_address',
                        'A scheduled message needs a recipient — type an address or pick a contact with one.',
                    );
                }

                if ($this->filled('occurred_at')) {
                    $validator->errors()->add('occurred_at', 'A scheduled message has not happened yet.');
                }
            },
        ];
    }

    /**
     * @return array<int, CommunicationStatus>
     */
    private function settableStatuses(): array
    {
        $channel = CommunicationChannel::tryFrom((string) $this->input('channel'));
        $direction = CommunicationDirection::tryFrom((string) $this->input('direction'));

        if ($channel === null || $direction === null) {
            return CommunicationStatus::loggable();
        }

        return CommunicationStatus::settableFor($channel, $direction);
    }
}
