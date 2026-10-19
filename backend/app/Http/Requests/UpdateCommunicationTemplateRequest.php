<?php

namespace App\Http\Requests;

use App\Enums\CommunicationChannel;
use App\Models\CommunicationTemplate;
use Illuminate\Validation\Rule;

/**
 * Editing a template (manager+). Every field is optional — a template can be renamed, retargeted
 * at another channel, reworded, or simply taken out of the composer's picker with `is_active`.
 */
class UpdateCommunicationTemplateRequest extends StoreCommunicationTemplateRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');

        return $template instanceof CommunicationTemplate
            && ($this->user()?->can('update', $template) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120', $this->liveNameUnique()],
            'channel' => ['sometimes', 'required', Rule::enum(CommunicationChannel::class)],
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
