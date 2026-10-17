<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanySiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageSites', $this->route('company')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'street_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'barangay' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city_municipality' => ['sometimes', 'nullable', 'string', 'max:255'],
            'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
