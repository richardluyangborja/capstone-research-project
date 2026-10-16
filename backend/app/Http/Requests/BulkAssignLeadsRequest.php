<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkAssignLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Salesreps claim a single lead; bulk assignment is manager/admin only (spec 03).
        $user = $this->user();

        return $user !== null && $user->role !== Role::Salesrep;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lead_ids' => ['required', 'array', 'min:1', 'max:100'],
            'lead_ids.*' => ['ulid', 'exists:leads,id'],
            'owner_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', Role::Salesrep->value),
            ],
        ];
    }
}
