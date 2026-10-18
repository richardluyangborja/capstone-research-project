<?php

namespace App\Http\Requests;

use App\Models\Communication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rendering a template (slice S5): the record it is being written about, and — when the caller
 * already knows — the person it is being written to.
 *
 * The record must be one the caller may **see**, which is checked in the controller: rendering
 * reads that record's own details, so being able to name it is not enough (spec 11 §S5).
 */
class RenderCommunicationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::in(array_keys(Communication::subjectTypes()))],
            'subject_id' => ['required', 'string'],
            'contact_id' => ['nullable', 'string', 'exists:contacts,id'],
        ];
    }
}
