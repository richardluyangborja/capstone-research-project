<?php

namespace App\Http\Resources;

use App\Models\Contract;
use App\Models\EmployeeDiscrepancy;
use App\Models\Opportunity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeDiscrepancy */
class EmployeeDiscrepancyResource extends JsonResource
{
    /**
     * One flag as the profile reads it. `status` is the stored state; `status_label` is the word
     * the pill shows, so "Under Review" is never mistaken for a second stored value (ADR-0033 §3).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'section' => $this->category->section(),
            'explanation' => $this->explanation,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'subject' => $this->subjectBlock(),
            'raised_by' => $this->raisedBy === null ? null : [
                'id' => $this->raisedBy->public_id,
                'name' => $this->raisedBy->name,
            ],
            'raised_at' => $this->created_at?->toIso8601String(),
            'resolved' => $this->resolved_at === null ? null : [
                'at' => $this->resolved_at->toIso8601String(),
                'by' => $this->resolvedBy === null ? null : [
                    'id' => $this->resolvedBy->public_id,
                    'name' => $this->resolvedBy->name,
                ],
                'note' => $this->resolution_note,
            ],
        ];
    }

    /**
     * The opportunity or contract the flag is about, when it names one and it was loaded.
     *
     * @return array{type: string, id: string, label: string}|null
     */
    private function subjectBlock(): ?array
    {
        if (! $this->resource->relationLoaded('subjectable')) {
            return null;
        }

        $subject = $this->resource->subjectable;

        return match (true) {
            $subject instanceof Opportunity => ['type' => 'opportunity', 'id' => $subject->id, 'label' => $subject->name],
            $subject instanceof Contract => ['type' => 'contract', 'id' => $subject->id, 'label' => $subject->title],
            default => null,
        };
    }
}
