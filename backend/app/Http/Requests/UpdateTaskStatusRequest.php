<?php

namespace App\Http\Requests;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a follow-up task through its lifecycle (spec 12 §S1). The move itself is checked
 * against `ActivityStatus::allowedTransitions()` in the controller — an illegal move is a 422,
 * not a 403, because the caller may act on the task but not that way.
 */
class UpdateTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Activity
            && ($this->user()?->can('transition', $task) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ActivityStatus::class)],
        ];
    }
}
