<?php

namespace App\Http\Resources;

use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contract */
class ContractResource extends JsonResource
{
    /**
     * Relations the full contract payload reads.
     *
     * @return array<int, string>
     */
    public static function detailRelations(): array
    {
        return [
            'company',
            'opportunity',
            'renewalOf:id,reference,title,status,start_date,end_date',
            'renewals:id,renewal_of_contract_id,reference,title,status,start_date,end_date',
            'owner:id,name',
            'rateCards.site',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'reference' => $this->reference,
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'reference' => $this->company->reference,
                'name' => $this->company->name,
                'status' => $this->company->status,
            ]),
            'opportunity_id' => $this->opportunity_id,
            'opportunity' => $this->whenLoaded('opportunity', fn () => $this->opportunity === null ? null : [
                'id' => $this->opportunity->id,
                'reference' => $this->opportunity->reference,
                'name' => $this->opportunity->name,
            ]),
            'renewal_of_contract_id' => $this->renewal_of_contract_id,
            'renewal_of' => $this->whenLoaded('renewalOf', fn () => $this->renewalOf === null ? null : [
                'id' => $this->renewalOf->id,
                'reference' => $this->renewalOf->reference,
                'title' => $this->renewalOf->title,
                'status' => $this->renewalOf->status,
                'start_date' => $this->renewalOf->start_date?->toDateString(),
                'end_date' => $this->renewalOf->end_date?->toDateString(),
            ]),
            'renewals' => $this->whenLoaded('renewals', fn () => $this->renewals->map(fn (Contract $renewal) => [
                'id' => $renewal->id,
                'reference' => $renewal->reference,
                'title' => $renewal->title,
                'status' => $renewal->status,
                'start_date' => $renewal->start_date?->toDateString(),
                'end_date' => $renewal->end_date?->toDateString(),
            ])),
            'title' => $this->title,
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'signed_at' => $this->signed_at?->toIso8601String(),
            'value' => $this->value,
            'currency' => $this->currency,
            // The commercial header (spec 16 §Contract commercial model, ADR-0024).
            'payment_terms' => $this->payment_terms,
            'po_reference' => $this->po_reference,
            'auto_renew' => (bool) $this->auto_renew,
            'notice_days' => $this->notice_days,
            'signatory_name' => $this->signatory_name,
            'signatory_position' => $this->signatory_position,
            'terms' => $this->terms,
            'notes' => $this->notes,
            // The structured commercial lines and their read-time totals (spec 16
            // §Contract commercial model, ADR-0024).
            'rate_cards' => ContractRateCardResource::collection($this->whenLoaded('rateCards')),
            'rate_cards_count' => $this->rate_cards_count ?? ($this->relationLoaded('rateCards') ? $this->rateCards->count() : 0),
            'commercial' => $this->whenLoaded('rateCards', function () use ($request): array {
                $summary = $this->commercialSummary();
                $canViewMargin = (bool) $request->user()?->can('viewMargin', $this->resource);

                return [
                    'client_monthly_value' => $this->money($summary['client_monthly_value']),
                    'internal_monthly_cost' => $canViewMargin && $summary['internal_monthly_cost'] !== null
                        ? $this->money($summary['internal_monthly_cost'])
                        : null,
                    'gross_margin' => $canViewMargin && $summary['gross_margin'] !== null
                        ? $this->money($summary['gross_margin'])
                        : null,
                    'gross_margin_percent' => $canViewMargin
                        ? $summary['gross_margin_percent']
                        : null,
                    'estimated_total_value' => $this->money($summary['estimated_total_value']),
                    'can_view_margin' => $canViewMargin,
                ];
            }),
            'days_until_expiry' => $this->daysUntilExpiry(),
            'is_expiring_soon' => $this->isExpiringSoon(),
            'is_signed' => $this->isSigned(),
            'is_active' => $this->isActive(),
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'owner_id' => $this->owner_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => $this->provenance(),
        ];
    }

    /**
     * @return array{source: string, read_only: bool, as_of: string}
     */
    private function provenance(): array
    {
        return [
            'source' => 'Contract Management (mocked, read-only)',
            'read_only' => true,
            'as_of' => now()->toIso8601String(),
        ];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
