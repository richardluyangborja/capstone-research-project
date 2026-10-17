<?php

namespace App\Http\Requests;

use App\Enums\LeadSource;
use App\Enums\OpportunityStage;
use App\Enums\Role;
use App\Models\Opportunity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Opportunity::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'string', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            // A deal is created open; won/lost is reached through the stage endpoint.
            'stage' => ['nullable', Rule::enum(OpportunityStage::class)->only(
                array_map(fn (OpportunityStage $stage): string => $stage->value, OpportunityStage::openStages()),
            )],
            // Amount is manager+ only (ADR-0012 §4) — a salesrep may not submit it.
            'amount' => ['nullable', 'numeric', 'min:0', Rule::prohibitedIf(fn (): bool => $this->user()?->role === Role::Salesrep)],
            'currency' => ['nullable', 'string', 'size:3'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'expected_close_date' => ['nullable', 'date'],
            'next_step' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', Rule::enum(LeadSource::class)],
            'primary_contact_id' => ['nullable', 'string', 'exists:contacts,id'],
            // Reassignment is manager+ (module 02 §3); a salesrep is always made the owner.
            'owner_id' => ['nullable', 'integer', 'exists:users,id', Rule::prohibitedIf(fn (): bool => $this->user()?->role === Role::Salesrep)],
        ];
    }
}
