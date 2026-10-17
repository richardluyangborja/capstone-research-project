<?php

namespace App\Models;

use App\Enums\CompanySize;
use App\Enums\CompanyStatus;
use App\Enums\CompanyTier;
use App\Enums\Role;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company/account: the organization a lead becomes and a client is built on (spec 06).
 * `Client` is a lifecycle state on this record, not a separate table (ADR-0001).
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'name',
        'trade_name',
        'industry',
        'size',
        'website',
        'description',
        'parent_company_id',
        'status',
        'tier',
        'client_since',
        'source_lead_id',
        'tags',
        'owner_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => CompanySize::class,
            'status' => CompanyStatus::class,
            'tier' => CompanyTier::class,
            'client_since' => 'date',
            'tags' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Company $company): void {
            $company->reference ??= static::nextReference();
            $company->status ??= CompanyStatus::Prospect;
        });
    }

    /**
     * Human-readable reference (display/search only — never a route key, see ADR-0007).
     */
    public static function nextReference(): string
    {
        $prefix = sprintf('CO-%d-', now()->year);

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->lockForUpdate()
            ->value('reference');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_company_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_company_id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(CompanySite::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subjectable', 'subject_type', 'subject_id');
    }

    public function sourceLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'source_lead_id');
    }

    /**
     * Role + ownership scoping (ADR-0004): salesrep own, manager team, admin all.
     * Unlike leads there is no shared queue — a company always has an owner.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->isAdmin()) {
            return $query;
        }

        if ($user->role === Role::Manager && $user->team_id !== null) {
            return $query->whereHas('owner', fn (Builder $q) => $q->where('team_id', $user->team_id));
        }

        return $query->where('owner_id', $user->id);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(trade_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(reference) LIKE ?', [$like])
                ->orWhereRaw('LOWER(industry) LIKE ?', [$like]);
        });
    }

    public function isClient(): bool
    {
        return $this->status->isClient();
    }

    /**
     * Reject parent assignments that would create a cycle (spec 06 §3): a company may
     * not be its own anchor, nor sit under one of its own descendants.
     */
    public function wouldCreateCycle(?self $parent): bool
    {
        if ($parent === null) {
            return false;
        }

        if ($parent->is($this)) {
            return true;
        }

        $cursor = $parent->parent;
        $depth = 0;

        while ($cursor !== null && $depth < 50) {
            if ($cursor->is($this)) {
                return true;
            }

            $cursor = $cursor->parent;
            $depth++;
        }

        return false;
    }

    /**
     * Reasons a soft delete is not allowed (spec 06 §6). Empty until opportunities and
     * contracts exist — submodules 08/09 add their active-dependency checks here.
     *
     * @return array<int, string>
     */
    public function deletionBlockers(): array
    {
        return [];
    }

    public function isDeletable(): bool
    {
        return $this->deletionBlockers() === [];
    }
}
