<?php

namespace App\Http\Requests;

use App\Models\Team;
use App\Rules\EligibleTeamManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminTeamRequest extends FormRequest
{
    /**
     * Team creation is an admin+ action; `TeamAdminPolicy` owns the boundary and the route
     * reports its standard 403 shape.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Team::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * A manager is optional at creation so a team can be built before its lead is chosen; when
     * one is named it must be an active manager account (`EligibleTeamManager`).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('teams', 'name')],
            'manager_id' => ['nullable', 'ulid', new EligibleTeamManager],
        ];
    }
}
