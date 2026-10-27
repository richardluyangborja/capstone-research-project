<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            && ($this->user()?->can('view', $company) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The role/team rule is the policy's job (`CompanyPolicy::assign`), so the target
            // may be any real user; a salesrep may only ever name themselves.
            'owner_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ];
    }
}
