<?php

namespace App\Http\Requests;

use App\Enums\DisqualificationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DisqualifyLeadRequest extends FormRequest
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
            'reason' => ['required', Rule::enum(DisqualificationReason::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
