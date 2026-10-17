<?php

namespace App\Http\Requests;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Opportunity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $opportunity = $this->route('opportunity');

        return $opportunity instanceof Opportunity
            && ($this->user()?->can('update', $opportunity) ?? false);
    }

    /**
     * Stage is deliberately absent — it moves through `POST /opportunities/{id}/stage`
     * so the pipeline rules and the timeline always apply.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0', Rule::prohibitedIf(fn (): bool => $this->user()?->role === Role::Salesrep)],
            'currency' => ['sometimes', 'string', 'size:3'],
            'probability' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'expected_close_date' => ['sometimes', 'nullable', 'date'],
            'next_step' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', Rule::enum(LeadSource::class)],
            'primary_contact_id' => ['sometimes', 'nullable', 'string', 'exists:contacts,id'],
            'owner_id' => ['sometimes', 'integer', 'exists:users,id', Rule::prohibitedIf(fn (): bool => $this->user()?->role === Role::Salesrep)],
        ];
    }
}
