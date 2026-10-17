<?php

namespace App\Http\Requests;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Client lifecycle move (spec 09 §1, §5) — manager+ only, and only along the state machine.
 */
class UpdateCompanyLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            && ($this->user()?->can('manageLifecycle', $company) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(CompanyStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
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
                $to = CompanyStatus::tryFrom((string) $this->input('status'));

                if (! $company instanceof Company || $to === null) {
                    return;
                }

                if (! $company->status->canTransitionTo($to)) {
                    $validator->errors()->add(
                        'status',
                        sprintf(
                            'Cannot change the client lifecycle from %s to %s.',
                            $company->status->value,
                            $to->value,
                        ),
                    );

                    return;
                }

                if ($to->requiresReason() && ! $this->filled('reason')) {
                    $validator->errors()->add('reason', 'A churn reason is required.');
                }
            },
        ];
    }
}
