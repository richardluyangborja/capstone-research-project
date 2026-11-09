<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminUserRequest extends FormRequest
{
    /**
     * The controller owns this gate through `authorizeOrFail` (spec 19 §Audit event
     * vocabulary): a rejected request must still append its `security.access_denied` audit
     * event, so authorization is deliberately not raised here.
     */
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
     * Safe profile edits only (spec 19 §Endpoint and policy inventory): name and contact
     * fields. Role, team, status, password and ownership fields are rejected outright if a
     * client tries to smuggle them into this payload — they each have their own endpoint.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target?->id)],
            'role' => ['prohibited'],
            'team_id' => ['prohibited'],
            'status' => ['prohibited'],
            'password' => ['prohibited'],
            'owner_id' => ['prohibited'],
        ];
    }
}
