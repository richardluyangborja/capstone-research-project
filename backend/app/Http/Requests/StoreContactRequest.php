<?php

namespace App\Http\Requests;

use App\Enums\ContactRoleType;
use App\Enums\ContactStatus;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            && ($this->user()?->can('create', [Contact::class, $company]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'role_type' => ['required', Rule::enum(ContactRoleType::class)],
            'email' => ['nullable', 'email', 'max:255'],
            // PH numbers: digits with optional +, spaces, dashes or parentheses (spec 07 §Validation).
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[+0-9()\s-]+$/'],
            'mobile' => ['nullable', 'string', 'max:40', 'regex:/^[+0-9()\s-]+$/'],
            'is_primary' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::enum(ContactStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
