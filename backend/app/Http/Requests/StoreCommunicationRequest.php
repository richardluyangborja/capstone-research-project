<?php

namespace App\Http\Requests;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationOutcome;
use App\Enums\CommunicationStatus;
use App\Models\Communication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // `draft` arrives with the composer (slice S2); `scheduled` belongs to module 04.
            'status' => ['nullable', Rule::in(array_map(
                static fn (CommunicationStatus $status): string => $status->value,
                CommunicationStatus::loggable(),
            ))],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'string', 'max:255'],
            'to_name' => ['nullable', 'string', 'max:255'],
            'to_address' => ['nullable', 'string', 'max:255'],
            // A logged message records something that has already happened.
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'outcome' => ['nullable', Rule::enum(CommunicationOutcome::class)],
        ];
    }
}
