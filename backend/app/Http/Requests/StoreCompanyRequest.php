<?php

namespace App\Http\Requests;

use App\Enums\CompanySize;
use App\Enums\CompanyStatus;
use App\Enums\CompanyTier;
use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Company::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', Rule::enum(CompanySize::class)],
            'website' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'parent_company_id' => ['nullable', 'ulid', Rule::exists('companies', 'id')],
            // Only manager+ may open a company in a non-prospect state (e.g. importing an
            // existing client); a salesrep's new company always starts as a prospect.
            'status' => [
                'nullable',
                Rule::prohibitedUnless($this->user()?->role !== Role::Salesrep),
                Rule::enum(CompanyStatus::class),
            ],
            'tier' => ['nullable', Rule::enum(CompanyTier::class)],
            'client_since' => ['nullable', 'date'],
            'source_lead_id' => ['nullable', 'ulid', Rule::exists('leads', 'id')],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }

    /**
     * A salesrep may only create companies they own; managers may hand one to their team
     * (spec 06 permissions). Runs after the base rules so `owner_id` exists is known good.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('owner_id')) {
                    return;
                }

                $target = User::find($this->input('owner_id'));
                $user = $this->user();

                if ($target === null || $user === null) {
                    return;
                }

                $candidate = new Company(['owner_id' => $target->id]);

                if (! $user->can('assign', [$candidate, $target])) {
                    $validator->errors()->add('owner_id', 'You may not assign this company to that user.');
                }
            },
        ];
    }
}
