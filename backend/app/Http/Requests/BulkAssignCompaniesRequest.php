<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkAssignCompaniesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A salesrep may only ever own their own accounts; bulk delegation is manager+ (spec 16).
        $user = $this->user();

        return $user !== null && $user->role !== Role::Salesrep;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_ids' => ['required', 'array', 'min:1', 'max:100'],
            'company_ids.*' => [
                'ulid',
                Rule::exists('companies', 'id')->whereNull('deleted_at'),
            ],
            'owner_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ];
    }
}
