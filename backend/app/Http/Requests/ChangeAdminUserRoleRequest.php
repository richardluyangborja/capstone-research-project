<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeAdminUserRoleRequest extends FormRequest
{
    /**
     * The controller owns this gate through `authorizeOrFail` (spec 19 §Audit event
     * vocabulary): a rejected request must still append its `security.access_denied` audit
     * event, so authorization is deliberately not raised here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An admin may only set salesrep/manager; only a superadmin assigns or removes `admin`;
     * `superadmin` is never assignable through this route (spec 19 §Endpoint and policy
     * inventory). The final-active-superadmin guard is deliberately **not** a payload rule —
     * the controller owns it so the block appends the `security.final_superadmin_guard`
     * critical audit event (spec 19 §Audit event vocabulary).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $assignable = $this->user()?->role === Role::Superadmin
            ? [Role::Admin->value, Role::Manager->value, Role::Salesrep->value]
            : [Role::Manager->value, Role::Salesrep->value];

        return [
            'role' => [
                'required',
                Rule::in($assignable),
                Rule::notIn([Role::Superadmin->value]),
            ],
        ];
    }
}
