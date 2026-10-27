<?php

namespace App\Http\Requests;

use App\Enums\ContractStatus;
use App\Models\Company;
use App\Models\Contract;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            && ($this->user()?->can('uploadContract', $company) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The signed document is the point of the record (spec 09 §API).
            'file' => [
                'required',
                'file',
                'mimes:pdf,png,jpg,jpeg',
                'max:'.(int) config('crm.contracts.max_kb'),
            ],
            'title' => ['required', 'string', 'max:255'],
            'opportunity_id' => ['nullable', 'ulid'],
            'renewal_of_contract_id' => ['nullable', 'ulid'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            // The commercial header (spec 16 §Contract commercial model, ADR-0024).
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'po_reference' => ['nullable', 'string', 'max:100'],
            'auto_renew' => ['sometimes', 'boolean'],
            'notice_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'signatory_name' => ['nullable', 'string', 'max:255'],
            'signatory_position' => ['nullable', 'string', 'max:255'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // A contract is uploaded as a draft (or already sent to the client). Only
            // mark-signed may reach `signed`, because it activates the client and fires
            // the Service handoff (spec 09 §1).
            'status' => [
                'nullable',
                Rule::enum(ContractStatus::class),
                Rule::in([ContractStatus::Draft->value, ContractStatus::Sent->value]),
            ],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $company = $this->route('company');

                if (! $company instanceof Company) {
                    return;
                }

                if ($this->filled('opportunity_id')) {
                    $belongs = $company->opportunities()->whereKey($this->input('opportunity_id'))->exists();

                    if (! $belongs) {
                        $validator->errors()->add(
                            'opportunity_id',
                            'That opportunity does not belong to this company.',
                        );
                    }
                }

                if (! $this->filled('renewal_of_contract_id')) {
                    return;
                }

                $renewed = Contract::withTrashed()->find($this->input('renewal_of_contract_id'));

                if ($renewed === null || $renewed->company_id !== $company->id) {
                    $validator->errors()->add(
                        'renewal_of_contract_id',
                        'A renewal must reference an earlier contract of the same company.',
                    );
                }
            },
        ];
    }
}
