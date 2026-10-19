<?php

namespace App\Http\Requests;

use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    /**
     * Anyone may owe themselves something (spec 12 §Permissions); whether the record may carry
     * the follow-up at all — and whether the caller may name that assignee — is decided against
     * the subject's own rights in the controller, exactly as logging a message is.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Activity::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::in(array_keys(Activity::subjectTypes()))],
            'subject_id' => ['required', 'string'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            // A reminder without a time is not a reminder (spec 12 §S1).
            'due_at' => ['required', 'date'],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }
}
