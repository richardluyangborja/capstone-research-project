<?php

namespace App\Http\Requests;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LogActivityRequest extends FormRequest
{
    /**
     * Activities attach to a lead, a company or a deal via the polymorphic `subject`
     * relation; logging requires update rights on whichever the route bound.
     */
    public function authorize(): bool
    {
        $subject = $this->route('lead') ?? $this->route('company') ?? $this->route('opportunity');

        return $subject !== null && ($this->user()?->can('update', $subject) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ActivityType::class)->except([ActivityType::System])],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(ActivityStatus::class)],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}
