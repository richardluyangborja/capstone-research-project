<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSurveyInvitationRequest extends FormRequest
{
    /**
     * Sending is a permission on the account, not on the survey (spec 13 §Permissions); the
     * controller checks the company's `update` right once the payload names one.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'string', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'contact_id' => ['nullable', 'string', Rule::exists('contacts', 'id')->whereNull('deleted_at')],
            'contract_id' => ['nullable', 'string', Rule::exists('contracts', 'id')->whereNull('deleted_at')],
            // A recipient may be a contact with an address, or an address typed by hand.
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * The cross-field rules: the send has to name a destination, and the survey has to be
     * sendable at all (business rules 3 and 5).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $survey = $this->route('survey');

                if ($survey instanceof Survey) {
                    if (! $survey->is_active) {
                        $validator->errors()->add('survey_id', 'Activate the survey before sending it.');
                    } elseif (! $survey->questions()->exists()) {
                        $validator->errors()->add('survey_id', 'Add at least one question before sending this survey.');
                    }
                }

                $contact = $this->filled('contact_id') ? Contact::find($this->input('contact_id')) : null;
                $company = $this->filled('company_id') ? Company::find($this->input('company_id')) : null;

                if ($contact !== null && $company !== null && $contact->company_id !== $company->id) {
                    $validator->errors()->add('contact_id', 'That contact does not belong to the selected company.');
                }

                $contract = $this->filled('contract_id') ? Contract::find($this->input('contract_id')) : null;

                if ($contract !== null && $company !== null && $contract->company_id !== $company->id) {
                    $validator->errors()->add('contract_id', 'That contract does not belong to the selected company.');
                }

                $hasAddress = $this->filled('recipient_email')
                    || ($contact !== null && filled($contact->email));

                if (! $hasAddress) {
                    $validator->errors()->add(
                        'recipient_email',
                        'Pick a contact with an email address, or type the address to send to.',
                    );
                }
            },
        ];
    }

    /**
     * The address the invitation goes to: the typed one, or the chosen contact's.
     */
    public function recipientEmail(): string
    {
        if ($this->filled('recipient_email')) {
            return (string) $this->input('recipient_email');
        }

        return (string) ($this->filled('contact_id') ? Contact::find($this->input('contact_id'))?->email : null);
    }

    /**
     * Who the invitation says it went to: the typed name, or the chosen contact's.
     */
    public function recipientName(): ?string
    {
        if ($this->filled('recipient_name')) {
            return (string) $this->input('recipient_name');
        }

        return $this->filled('contact_id')
            ? Contact::find($this->input('contact_id'))?->name
            : null;
    }
}
