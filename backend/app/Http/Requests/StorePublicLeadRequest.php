<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The public landing form (spec 16 §Public intake, ADR-0026 §3).
 *
 * This is an intake envelope, not the authenticated lead API: it accepts only what the page
 * asks for, always creates an unassigned website lead, and never lets the caller pick an owner,
 * status, score or sensitive field. The hidden `website` field is the honeypot.
 */
class StorePublicLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'contact_phone' => ['nullable', 'string', 'max:50', 'regex:/^[+0-9()\s-]+$/', 'required_without:contact_email'],
            'industry' => ['nullable', 'string', 'max:255'],
            'service_interest' => ['nullable', 'string', 'max:255'],
            'estimated_headcount' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'needed_roles' => ['nullable', 'string', 'max:2000'],
            'target_start_date' => ['nullable', 'date'],
            'message' => ['nullable', 'string', 'max:5000'],
            'consent' => ['required', 'accepted'],
            // Honeypot: a real browser never fills this hidden field.
            'website' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact_email.required_without' => 'Give us an email address or a phone number.',
            'contact_phone.required_without' => 'Give us an email address or a phone number.',
            'consent.accepted' => 'Please agree to be contacted about this request.',
            'website.prohibited' => 'This request could not be accepted.',
        ];
    }
}
