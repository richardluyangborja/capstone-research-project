<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The one thing written after a submission: what was done about it (module 05 slice S4,
 * spec 13 §Business rules 8). Whether the caller may resolve at all is the policy's business,
 * checked in the controller.
 */
class ResolveSurveyResponseRequest extends FormRequest
{
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
            'note' => ['required', 'string', 'max:2000'],
        ];
    }
}
