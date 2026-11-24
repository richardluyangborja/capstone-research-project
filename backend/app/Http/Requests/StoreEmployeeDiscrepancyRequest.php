<?php

namespace App\Http\Requests;

use App\Enums\DiscrepancyCategory;
use App\Models\Contract;
use App\Models\Opportunity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A rep's escalation of one disputed figure (module 09 slice T4, spec 21 §T4, ADR-0033).
 *
 * Whether the caller may raise a flag on this employee at all is the policy's business, checked in
 * the controller. What is validated here is the flag itself: a category, an explanation a manager
 * can act on (10–1000 characters), and — only for the commercial categories — an optional CRM
 * record the caller can actually see.
 */
class StoreEmployeeDiscrepancyRequest extends FormRequest
{
    /**
     * The CRM records a flag may name. Both are ULID-keyed and both have a `view` policy.
     *
     * @var array<string, class-string>
     */
    private const REFERENCEABLE = [
        'opportunity' => Opportunity::class,
        'contract' => Contract::class,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The morph class for the referenced record — the API speaks `opportunity`/`contract`, while
     * the table stores the class name `Activity` already uses for its own subjects.
     */
    public function subjectClass(): ?string
    {
        $type = $this->input('subject_type');

        return is_string($type) && isset(self::REFERENCEABLE[$type]) ? self::REFERENCEABLE[$type] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(DiscrepancyCategory::class)],
            'explanation' => ['required', 'string', 'min:10', 'max:1000'],
            'subject_type' => ['nullable', Rule::in(array_keys(self::REFERENCEABLE))],
            'subject_id' => ['nullable', 'required_with:subject_type', 'ulid'],
        ];
    }

    /**
     * A reference must exist, be visible to the caller, and belong to a category that can carry
     * one — attendance and leave live only in Core 2 (ADR-0033 §5).
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $type = $this->input('subject_type');
                $id = $this->input('subject_id');

                if ($type === null && $id === null) {
                    return;
                }

                $category = DiscrepancyCategory::tryFrom((string) $this->input('category'));

                if ($category !== null && ! $category->canReferenceRecord()) {
                    $validator->errors()->add('subject_type', 'An attendance or leave-balance flag cannot reference a CRM record.');

                    return;
                }

                if (is_string($type) && is_string($id) && isset(self::REFERENCEABLE[$type])) {
                    $model = self::REFERENCEABLE[$type]::query()->find($id);

                    if ($model === null || ! $this->user()->can('view', $model)) {
                        $validator->errors()->add('subject_id', 'The referenced record was not found.');
                    }
                }
            },
        ];
    }
}
