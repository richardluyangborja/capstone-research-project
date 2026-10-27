<?php

namespace App\Http\Requests;

use App\Enums\ContractStatus;
use App\Enums\Role;
use App\Models\Contract;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('contract')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:100'],
            'po_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'auto_renew' => ['sometimes', 'boolean'],
            'notice_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'signatory_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'signatory_position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(ContractStatus::class)],
        ];
    }

    /**
     * Contract transitions are policed like the company ones (spec 09 §1): an illegal move
     * is a 422, `signed` has its own action, and termination is manager+.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $contract = $this->route('contract');
                $user = $this->user();

                if (! $contract instanceof Contract || ! $this->filled('status')) {
                    return;
                }

                $to = ContractStatus::tryFrom((string) $this->input('status'));

                if ($to === null || $to === $contract->status) {
                    return;
                }

                if (! $contract->status->canTransitionTo($to)) {
                    $validator->errors()->add(
                        'status',
                        sprintf(
                            'Cannot change the contract from %s to %s.',
                            $contract->status->value,
                            $to->value,
                        ),
                    );

                    return;
                }

                if ($to === ContractStatus::Signed) {
                    $validator->errors()->add(
                        'status',
                        'Use mark-signed: signing activates the client and fires the Service handoff.',
                    );

                    return;
                }

                if ($to === ContractStatus::Terminated && $user?->role === Role::Salesrep) {
                    $validator->errors()->add(
                        'status',
                        'Only a manager or admin may terminate a contract.',
                    );
                }
            },
        ];
    }
}
