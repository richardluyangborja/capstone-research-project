<?php

namespace App\Http\Requests;

use App\Enums\CommunicationOutcome;
use App\Models\Communication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting a logged message. Channel, direction and status are deliberately absent: the
 * channel drives the timeline mirror, and a delivered record's state is not a draft field
 * (spec 11 §Business rules, ADR-0017 §6).
 */
class UpdateCommunicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $communication = $this->route('communication');

        return $communication instanceof Communication
            && ($this->user()?->can('update', $communication) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'contact_id' => ['nullable', 'string', 'exists:contacts,id'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'string', 'max:255'],
            'to_name' => ['nullable', 'string', 'max:255'],
            'to_address' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'outcome' => ['nullable', Rule::enum(CommunicationOutcome::class)],
        ];
    }
}
