<?php

namespace App\Http\Requests;

use App\Enums\CommunicationChannel;
use App\Models\CommunicationTemplate;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommunicationTemplateRequest extends FormRequest
{
    /**
     * Authoring a template is manager+ (spec 11 §Permissions, slice S5).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', CommunicationTemplate::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', $this->liveNameUnique()],
            'channel' => ['required', Rule::enum(CommunicationChannel::class)],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // The name is a picker label, not free text — leading/trailing space never helps.
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * The name is a picker label: two templates answering to one would make the choice ambiguous,
     * so it is unique among **live** templates, case-insensitively.
     *
     * That is a validation rule rather than a database constraint because a soft delete frees the
     * name (spec 11 §S5) — and a unique index cannot say "unless retired". `restore` re-checks the
     * same thing, so bringing a template back can never shadow a live one.
     */
    protected function liveNameUnique(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $current = $this->route('template');

            $taken = CommunicationTemplate::query()
                ->when($current !== null, fn ($query) => $query->whereKeyNot($current))
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                ->exists();

            if ($taken) {
                $fail('Another template already uses that name.');
            }
        };
    }
}
