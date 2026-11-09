<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminTeamRequest extends FormRequest
{
    /**
     * The controller owns this gate through `authorizeOrFail` so a refused mutation still
     * appends its `security.access_denied` audit event (spec 19 §Audit event vocabulary).
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * The team's name only. Manager changes have their own endpoint and audit semantics.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $team = $this->route('team');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('teams', 'name')->ignore($team?->id),
            ],
        ];
    }
}
