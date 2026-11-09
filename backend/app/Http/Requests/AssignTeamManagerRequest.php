<?php

namespace App\Http\Requests;

use App\Rules\EligibleTeamManager;
use Illuminate\Foundation\Http\FormRequest;

class AssignTeamManagerRequest extends FormRequest
{
    /**
     * The controller owns this gate through `authorizeOrFail` so a refused mutation still
     * appends its `security.access_denied` audit event (spec 19 §Audit event vocabulary).
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
            'manager_id' => ['required', 'ulid', new EligibleTeamManager],
        ];
    }
}
