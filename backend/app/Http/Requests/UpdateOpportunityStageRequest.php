<?php

namespace App\Http\Requests;

use App\Enums\LostReason;
use App\Enums\OpportunityStage;
use App\Models\Opportunity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOpportunityStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $opportunity = $this->route('opportunity');

        return $opportunity instanceof Opportunity
            && ($this->user()?->can('changeStage', $opportunity) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::enum(OpportunityStage::class)],
            'lost_reason' => ['nullable', 'required_if:stage,lost', Rule::enum(LostReason::class)],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
