<?php

namespace App\Http\Requests;

use App\Enums\DisqualificationReason;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('lead')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(LeadStatus::class)],
            'reason' => ['nullable', Rule::enum(DisqualificationReason::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Reject illegal transitions with a 422 on the `status` field (spec 04 §1).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $lead = $this->route('lead');
                $to = LeadStatus::tryFrom((string) $this->input('status'));

                if (! $lead instanceof Lead || $to === null) {
                    return;
                }

                if ($to === LeadStatus::Converted) {
                    $validator->errors()->add(
                        'status',
                        'Converting a lead into a client is handled by the conversion flow (submodule 08).',
                    );

                    return;
                }

                if (! in_array($to, $lead->status->actionableTransitions(), true)) {
                    $validator->errors()->add(
                        'status',
                        sprintf('Cannot change status from %s to %s.', $lead->status->value, $to->value),
                    );

                    return;
                }

                if ($to === LeadStatus::Disqualified && blank($this->input('reason'))) {
                    $validator->errors()->add('reason', 'A disqualification reason is required.');
                }
            },
        ];
    }
}
