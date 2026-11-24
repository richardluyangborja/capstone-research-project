<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What was done about a flag (module 09 slice T4, spec 21 §T4, ADR-0033). The note is optional:
 * some flags are simply confirmed and closed. Who may resolve at all is the policy's business,
 * checked in the controller.
 */
class ResolveEmployeeDiscrepancyRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
