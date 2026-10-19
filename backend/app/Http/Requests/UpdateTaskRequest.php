<?php

namespace App\Http\Requests;

use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a follow-up task (spec 12 §S1): its wording, when it is owed and who owes it. The
 * record it hangs off is fixed — a follow-up that changed hands would be a different promise.
 * Whether the caller may name a given assignee is checked in the controller (`assign`).
 */
class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Activity
            && ($this->user()?->can('update', $task) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'due_at' => ['sometimes', 'required', 'date'],
            'owner_id' => ['sometimes', 'required', 'integer', Rule::exists('users', 'id')],
        ];
    }
}
