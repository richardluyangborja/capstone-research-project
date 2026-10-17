<?php

namespace App\Http\Requests;

use App\Enums\ContactRoleType;
use App\Enums\ContactStatus;
use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contact = $this->route('contact');

        return $contact instanceof Contact
            && ($this->user()?->can('update', $contact) ?? false);
    }

    /**
     * `company_id` is deliberately absent: a contact belongs to one company (spec 07 §1) and
     * moving it would silently change who may see it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role_type' => ['sometimes', 'required', Rule::enum(ContactRoleType::class)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[+0-9()\s-]+$/'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[+0-9()\s-]+$/'],
            'is_primary' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'required', Rule::enum(ContactStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
