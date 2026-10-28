<?php

namespace App\Models;

use App\Enums\BillingUnit;
use Database\Factories\ContractRateCardFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One commercial line on a contract (module 01 refresh S7, spec 16 §Contract commercial
 * model, ADR-0024). Totals are derived on read; only the inputs are stored.
 */
class ContractRateCard extends Model
{
    /** @use HasFactory<ContractRateCardFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'contract_id',
        'site_id',
        'role_title',
        'headcount',
        'billing_unit',
        'bill_rate',
        'pay_rate',
        'units_per_period',
        'notes',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_unit' => BillingUnit::class,
            'headcount' => 'integer',
            'bill_rate' => 'decimal:2',
            'pay_rate' => 'decimal:2',
            'units_per_period' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ContractRateCard $card): void {
            // Hourly lines bill monthly hours; the other units bill once per period.
            $card->units_per_period ??= $card->billing_unit === BillingUnit::Hourly ? 176 : 1;
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CompanySite::class, 'site_id');
    }

    /**
     * Hours in a month for an hourly line; otherwise 1.
     */
    public function effectiveUnitsPerPeriod(): float
    {
        if ($this->billing_unit !== BillingUnit::Hourly) {
            return 1.0;
        }

        return (float) ($this->units_per_period ?? 176);
    }

    /**
     * The client-facing monthly contribution. Placement lines are one-time and return 0.
     */
    public function clientMonthlyValue(): float
    {
        if (! $this->billing_unit->contributesToMonthly()) {
            return 0.0;
        }

        return round(
            $this->headcount * (float) $this->bill_rate * $this->effectiveUnitsPerPeriod(),
            2,
        );
    }

    /**
     * The internal monthly cost. A placement line has no monthly cost; a line without a
     * pay rate contributes nothing (and the contract summary suppresses the margin).
     */
    public function internalMonthlyCost(): float
    {
        if (! $this->billing_unit->contributesToMonthly() || $this->pay_rate === null) {
            return 0.0;
        }

        return round(
            $this->headcount * (float) $this->pay_rate * $this->effectiveUnitsPerPeriod(),
            2,
        );
    }

    /**
     * The one-time fee a placement line adds to the estimated contract value.
     */
    public function placementFee(): float
    {
        if ($this->billing_unit !== BillingUnit::Placement) {
            return 0.0;
        }

        return round($this->headcount * (float) $this->bill_rate, 2);
    }
}
