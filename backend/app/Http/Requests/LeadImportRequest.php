<?php

namespace App\Http\Requests;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;

class LeadImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Lead::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048', 'extensions:csv,txt'],
            // The importer's role decides the target: a salesrep is forced to self, a
            // manager may choose a team member and an admin any salesrep (spec 01 rule 7).
            'owner_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => 'Upload a CSV file (.csv or .txt).',
            'file.max' => 'The CSV file may not be larger than 2 MB.',
        ];
    }
}
