<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeAdminUserTeamRequest extends FormRequest
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
     * Moving a user between teams never reassigns their CRM records — ownership changes go
     * through the existing domain delegation endpoints (spec 19 §Resource boundaries).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'team_id' => ['nullable', 'ulid', Rule::exists('teams', 'id')],
        ];
    }
}
