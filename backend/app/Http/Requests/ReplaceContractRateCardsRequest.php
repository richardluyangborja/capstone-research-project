<?php

namespace App\Http\Requests;

use App\Enums\BillingUnit;
use App\Models\Contract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Replaces a contract's rate-card set (module 01 refresh S7, ADR-0024). The array order is
 * the display order, and writing is manager+.
 */
class ReplaceContractRateCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contract = $this->route('contract');

        return $contract instanceof Contract
            && ($this->user()?->can('manageRateCards', $contract) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $contract = $this->route('contract');
        $companyId = $contract instanceof Contract ? $contract->company_id : null;

        return [
            // An empty set is legal — a contract may have no rate cards.
            'rate_cards' => ['present', 'array', 'max:50'],
            'rate_cards.*.role_title' => ['required', 'string', 'max:255'],
            'rate_cards.*.headcount' => ['required', 'integer', 'min:1', 'max:100000'],
            'rate_cards.*.billing_unit' => ['required', Rule::enum(BillingUnit::class)],
            'rate_cards.*.bill_rate' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'rate_cards.*.pay_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'rate_cards.*.units_per_period' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'rate_cards.*.site_id' => [
                'nullable',
                'ulid',
                Rule::exists('company_sites', 'id')->where(
                    fn ($query) => $query->where('company_id', $companyId),
                ),
            ],
            'rate_cards.*.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rate_cards.*.site_id.exists' => 'A rate card site must belong to the contract’s company.',
        ];
    }
}
