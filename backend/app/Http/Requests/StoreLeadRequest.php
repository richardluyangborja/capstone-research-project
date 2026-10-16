<?php

namespace App\Http\Requests;

use App\Enums\LeadSource;
use App\Enums\Urgency;
use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Lead::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255', 'required_without:contact_phone'],
            'contact_phone' => ['nullable', 'string', 'max:50', 'required_without:contact_email'],
            'contact_position' => ['nullable', 'string', 'max:255'],
            'source' => ['required', Rule::enum(LeadSource::class)],
            'source_detail' => ['nullable', 'string', 'max:255', 'required_if:source,other'],
            'industry' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'service_interest' => ['nullable', 'string', 'max:255'],
            'estimated_headcount' => ['nullable', 'integer', 'min:1'],
            'needed_roles' => ['nullable', 'string'],
            'target_start_date' => ['nullable', 'date'],
            'urgency' => ['nullable', Rule::enum(Urgency::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
