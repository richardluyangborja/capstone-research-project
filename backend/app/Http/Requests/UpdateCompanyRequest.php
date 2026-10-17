<?php

namespace App\Http\Requests;

use App\Enums\CompanySize;
use App\Enums\CompanyStatus;
use App\Enums\CompanyTier;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('company')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:255'],
            'size' => ['sometimes', 'nullable', Rule::enum(CompanySize::class)],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'parent_company_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('companies', 'id')],
            // Lifecycle advancement belongs to submodule 09; only an admin may override
            // the status directly (spec 06 §4).
            'status' => [
                'sometimes',
                Rule::prohibitedUnless($this->user()?->role->isAdmin() ?? false),
                Rule::enum(CompanyStatus::class),
            ],
            'tier' => ['sometimes', 'nullable', Rule::enum(CompanyTier::class)],
            'client_since' => ['sometimes', 'nullable', 'date'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'owner_id' => ['sometimes', 'integer', Rule::exists('users', 'id')],
        ];
    }

    /**
     * Field-level guardrails with a 422 (spec 06 §3): only a manager/admin may (re)link a
     * branch, and the link may never create a cycle.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $company = $this->route('company');
                $user = $this->user();

                if (! $company instanceof Company || $user === null) {
                    return;
                }

                if (! $this->exists('parent_company_id')) {
                    return;
                }

                if (! $user->can('manageParent', $company)) {
                    $validator->errors()->add(
                        'parent_company_id',
                        'Only a manager or admin may change a company branch link.',
                    );

                    return;
                }

                if (! $this->filled('parent_company_id')) {
                    return;
                }

                $parent = Company::find($this->input('parent_company_id'));

                if ($parent === null) {
                    return;
                }

                if ($company->wouldCreateCycle($parent)) {
                    $validator->errors()->add(
                        'parent_company_id',
                        'A company cannot be its own parent or sit under one of its own branches.',
                    );
                }
            },
            function (Validator $validator): void {
                $company = $this->route('company');
                $user = $this->user();

                if (! $company instanceof Company || ! $this->filled('owner_id') || $user === null) {
                    return;
                }

                $target = User::find($this->input('owner_id'));

                if ($target === null) {
                    return;
                }

                if (! $user->can('assign', [$company, $target])) {
                    $validator->errors()->add('owner_id', 'You may not assign this company to that user.');
                }
            },
        ];
    }
}
