<?php

namespace App\Models;

use App\Enums\ContractStatus;
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
