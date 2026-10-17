<?php

namespace App\Http\Requests;

use App\Enums\CompanySize;
use App\Enums\ContactRoleType;
use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lead = $this->route('lead');

        return $lead instanceof Lead
            && ($this->user()?->can('convert', $lead) ?? false);
    }

    /**
     * Either link an existing company or send the details for a new one (spec 08 §API).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'link_company_id' => ['nullable', 'ulid', Rule::exists('companies', 'id')->whereNull('deleted_at')],

            'company' => ['required_without:link_company_id', 'array'],
            'company.name' => ['required_with:company', 'string', 'max:255'],
            'company.trade_name' => ['nullable', 'string', 'max:255'],
            'company.industry' => ['nullable', 'string', 'max:255'],
            'company.size' => ['nullable', Rule::enum(CompanySize::class)],
            'company.website' => ['nullable', 'string', 'max:255'],
            'company.description' => ['nullable', 'string'],

            'contacts' => ['required', 'array', 'min:1'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.position' => ['nullable', 'string', 'max:255'],
            'contacts.*.role_type' => ['required', Rule::enum(ContactRoleType::class)],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40', 'regex:/^[+0-9()\s-]+$/'],
            'contacts.*.mobile' => ['nullable', 'string', 'max:40', 'regex:/^[+0-9()\s-]+$/'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],

            'opportunity' => ['required', 'array'],
            'opportunity.name' => ['required', 'string', 'max:255'],
            'opportunity.amount' => ['nullable', 'numeric', 'min:0'],
            'opportunity.probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'opportunity.expected_close_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company.required_without' => 'Send the new company details or link an existing company.',
            'contacts.min' => 'A converted client needs at least one contact.',
        ];
    }
}
