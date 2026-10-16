<?php

namespace App\Http\Requests;

use App\Enums\LeadRating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RateLeadRequest extends FormRequest
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
            'rating' => ['nullable', Rule::enum(LeadRating::class), 'required_without:score'],
            'score' => ['nullable', 'integer', 'between:0,100', 'required_without:rating'],
        ];
    }
}
