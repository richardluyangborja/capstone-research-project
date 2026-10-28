<?php

namespace App\Models;

use App\Enums\ContractStatus;
use Carbon\CarbonInterface;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A signed engagement with a client (spec 09). The uploaded document lives on a private
 * disk and is streamed through an authorized endpoint — the API never exposes the path.
 */
class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'opportunity_id',
        'renewal_of_contract_id',
        'title',
        'status',
        'start_date',
        'end_date',
        'signed_at',
        'value',
        'currency',
        'payment_terms',
        'po_reference',
        'auto_renew',
        'notice_days',
        'signatory_name',
        'signatory_position',
        'terms',
        'notes',
        'file_path',
        'file_name',
        'file_size',
        'file_mime',
        'owner_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'signed_at' => 'datetime',
            'value' => 'decimal:2',
            'auto_renew' => 'boolean',
            'notice_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Contract $contract): void {
            $contract->reference ??= static::nextReference();
            $contract->status ??= ContractStatus::Draft;
        });
    }

    /**
     * Human-readable reference (display/search only — never a route key, see ADR-0007).
     */
    public static function nextReference(): string
    {
        $prefix = sprintf('CON-%d-', now()->year);

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->lockForUpdate()
            ->value('reference');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function renewalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewal_of_contract_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'renewal_of_contract_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The contract's commercial lines, in display order (module 01 refresh S7).
     */
    public function rateCards(): HasMany
    {
        return $this->hasMany(ContractRateCard::class)->orderBy('position')->orderBy('id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subjectable', 'subject_type', 'subject_id');
    }

    /**
     * The detailed communication history for this contract (module 03, spec 11).
     */
    public function communications(): MorphMany
    {
        return $this->morphMany(Communication::class, 'subjectable', 'subject_type', 'subject_id');
    }

    /**
     * A contract inherits its company's visibility (ADR-0004, ADR-0013): seeing the
     * account means seeing the contracts on it.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('company', fn (Builder $company) => $company->visibleTo($user));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->whereRaw('LOWER(title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(reference) LIKE ?', [$like]);
        });
    }

    /**
     * Signed contracts inside the renewal window (module 04 slice S4, spec 12 §11) — the
     * contracts the generator raises a renewal task for. The SQL is the query form of
     * `isExpiringSoon()`, which the resource reads per row.
     */
    public function scopeSignedExpiringSoon(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $today = ($now ?? now())->copy()->startOfDay();

        return $query
            ->where('status', ContractStatus::Signed)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', $today->copy()->addDays((int) config('crm.contracts.expiring_soon_days')));
    }

    public function isSigned(): bool
    {
        return $this->status === ContractStatus::Signed;
    }

    /**
     * An active (signed) contract blocks deleting the company (spec 06 §6).
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Days until expiry, negative once past. Null when no end date is recorded.
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->end_date === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->end_date->startOfDay(), false);
    }

    /**
     * A signed contract inside the renewal window (config/crm.php — spec 09 §6).
     */
    public function isExpiringSoon(): bool
    {
        $days = $this->daysUntilExpiry();

        return $this->isSigned()
            && $days !== null
            && $days <= (int) config('crm.contracts.expiring_soon_days');
    }

    /**
     * Whole months in the contract term, minimum 1 — the multiplier for the estimated
     * contract value (spec 16 §Contract commercial model).
     */
    public function wholeMonthsInTerm(): int
    {
        if ($this->start_date === null || $this->end_date === null) {
            return 1;
        }

        return max(1, (int) $this->start_date->diffInMonths($this->end_date));
    }

    /**
     * The commercial totals, derived from the rate cards on read and never stored. An
     * `internal_monthly_cost` of null means no recurring line carries a pay rate; a null
     * margin means at least one recurring line is missing its pay rate (ADR-0024 §4).
     *
     * @return array{
     *     client_monthly_value: float,
     *     internal_monthly_cost: float|null,
     *     gross_margin: float|null,
     *     gross_margin_percent: float|null,
     *     estimated_total_value: float,
     *     placement_fees: float,
     *     rate_card_count: int
     * }
     */
    public function commercialSummary(): array
    {
        $cards = $this->relationLoaded('rateCards') ? $this->rateCards : $this->rateCards()->get();

        $clientMonthly = 0.0;
        $internalMonthly = 0.0;
        $placementFees = 0.0;
        $recurringLines = 0;
        $hasPayRate = false;
        $missingPayRate = false;

        foreach ($cards as $card) {
            if ($card->billing_unit->contributesToMonthly()) {
                $recurringLines++;
                $clientMonthly += $card->clientMonthlyValue();

                if ($card->pay_rate === null) {
                    $missingPayRate = true;
                } else {
                    $hasPayRate = true;
                    $internalMonthly += $card->internalMonthlyCost();
                }

                continue;
            }

            $placementFees += $card->placementFee();
        }

        $clientMonthly = round($clientMonthly, 2);
        $internalMonthly = round($internalMonthly, 2);
        $placementFees = round($placementFees, 2);

        $margin = $recurringLines === 0 || $missingPayRate
            ? null
            : round($clientMonthly - $internalMonthly, 2);

        $marginPercent = $margin === null || $clientMonthly <= 0
            ? null
            : round($margin / $clientMonthly * 100, 2);

        return [
            'client_monthly_value' => $clientMonthly,
            'internal_monthly_cost' => $hasPayRate ? $internalMonthly : null,
            'gross_margin' => $margin,
            'gross_margin_percent' => $marginPercent,
            'estimated_total_value' => round(
                $clientMonthly * $this->wholeMonthsInTerm() + $placementFees,
                2,
            ),
            'placement_fees' => $placementFees,
            'rate_card_count' => $cards->count(),
        ];
    }

    /**
     * Whether the uploaded document is present on disk.
     */
    public function hasFile(): bool
    {
        return $this->file_path !== null;
    }

    /**
     * Mark the contract signed. Side effects (client activation + service handoff) are
     * the caller's — see ContractController::markSigned.
     */
    public function markSigned(?Carbon $signedAt = null): void
    {
        $this->status = ContractStatus::Signed;
        $this->signed_at = $signedAt ?? now();
        $this->save();
    }
}
