<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminUserRequest extends FormRequest
{
    /**
     * Creating a directory account is an admin+ action; which roles the caller may name is a
     * payload rule here (spec 19 §Endpoint and policy inventory): an admin creates
     * manager/salesrep accounts, only a superadmin creates an admin, and **no** route creates
     * a superadmin.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $assignable = $this->user()?->role === Role::Superadmin
            ? [Role::Admin->value, Role::Manager->value, Role::Salesrep->value]
            : [Role::Manager->value, Role::Salesrep->value];

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in($assignable)],
            'team_id' => ['nullable', 'ulid', Rule::exists('teams', 'id')],
            // Optional: when omitted the account starts without a usable password and signs in
            // through the invitation flow. It is never echoed back (spec 19 §Redaction rules).
            'password' => ['nullable', 'string', 'min:8'],
        ];
    }
}
