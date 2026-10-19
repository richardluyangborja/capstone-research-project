<?php

namespace App\Http\Requests;

use App\Enums\CommunicationOutcome;
use App\Enums\CommunicationStatus;
use App\Models\Communication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Rescheduling a scheduled message moves when it is owed; nothing else has one.
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'outcome' => ['nullable', Rule::enum(CommunicationOutcome::class)],
        ];
    }

    /**
     * A scheduled message keeps its two scheduling facts straight: it always has a due moment
     * (rule 4), and it does not have a moment it happened — it has not happened (spec 12 §S2).
     * Everything else keeps its own record and carries no `scheduled_for` at all.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $communication = $this->route('communication');

                if (! $communication instanceof Communication) {
                    return;
                }

                if ($communication->status === CommunicationStatus::Scheduled) {
                    if ($this->exists('scheduled_for') && ! $this->filled('scheduled_for')) {
                        $validator->errors()->add('scheduled_for', 'A scheduled message needs a due moment.');
                    }

                    if ($this->filled('occurred_at')) {
                        $validator->errors()->add('occurred_at', 'A scheduled message has not happened yet.');
                    }

                    return;
                }

                if ($this->exists('scheduled_for')) {
                    $validator->errors()->add('scheduled_for', 'Only a scheduled message carries a due moment.');
                }
            },
        ];
    }
}
