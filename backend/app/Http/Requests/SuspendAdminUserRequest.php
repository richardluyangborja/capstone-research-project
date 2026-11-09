<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SuspendAdminUserRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status_reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
