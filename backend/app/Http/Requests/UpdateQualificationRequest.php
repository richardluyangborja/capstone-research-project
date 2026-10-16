<?php

namespace App\Http\Requests;

use App\Enums\Urgency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('lead')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estimated_headcount' => ['nullable', 'integer', 'min:1'],
            'needed_roles' => ['nullable', 'string'],
            'target_start_date' => ['nullable', 'date'],
            'budget_rate' => ['nullable', 'numeric', 'min:0'],
            'authority_confirmed' => ['nullable', 'boolean'],
            'work_site' => ['nullable', 'string', 'max:255'],
            'urgency' => ['nullable', Rule::enum(Urgency::class)],
            'qualification_notes' => ['nullable', 'string'],
        ];
    }
}
