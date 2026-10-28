<?php

namespace App\Http\Resources;

use App\Models\ContractRateCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContractRateCard */
class ContractRateCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Every returned card belongs to a contract the caller may view; the sensitive
        // split is the role rule (manager+ sees pay and internal cost — ADR-0024 §5).
        $canViewMargin = (bool) $request->user()?->canSeeSensitiveData();

        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'site_id' => $this->site_id,
            'site' => $this->whenLoaded('site', fn () => $this->site === null ? null : [
                'id' => $this->site->id,
                'label' => $this->site->label,
            ]),
            'role_title' => $this->role_title,
            'headcount' => $this->headcount,
            'billing_unit' => $this->billing_unit,
            'bill_rate' => $this->bill_rate,
            'pay_rate' => $canViewMargin ? $this->pay_rate : null,
            'units_per_period' => $this->units_per_period,
            'notes' => $this->notes,
            'position' => $this->position,
            'line_client_monthly' => $this->money($this->clientMonthlyValue()),
            'line_internal_monthly' => $canViewMargin
                ? $this->money($this->internalMonthlyCost())
                : null,
            'line_placement_fee' => $this->money($this->placementFee()),
            'can_view_margin' => $canViewMargin,
        ];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
