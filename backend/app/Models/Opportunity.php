<?php

namespace App\Models;

use App\Enums\LeadSource;
use App\Enums\LostReason;
use App\Enums\OpportunityStage;
use App\Enums\Role;
use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A deal on a company (spec 08). Opportunities are opened by lead→client conversion and
 * carry the staffing pipeline (ADR-0002); the pipeline board itself is a later module.
 */
class Opportunity extends Model
{
    /** @use HasFactory<OpportunityFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'lead_id',
        'name',
        'stage',
        'amount',
        'currency',
        'probability',
        'expected_close_date',
        'actual_close_date',
        'source',
        'primary_contact_id',
        'owner_id',
        'lost_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => OpportunityStage::class,
            'source' => LeadSource::class,
            'lost_reason' => LostReason::class,
            'amount' => 'decimal:2',
            'expected_close_date' => 'date',
            'actual_close_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Opportunity $opportunity): void {
            $opportunity->reference ??= static::nextReference();
            $opportunity->stage ??= OpportunityStage::NewInquiry;
            // Probability defaults from the stage and stays overridable (spec 00).
            $opportunity->probability ??= $opportunity->stage->defaultProbability();
        });
    }

    /**
     * Human-readable reference (display/search only — never a route key, see ADR-0007).
     */
    public static function nextReference(): string
    {
        $prefix = sprintf('OPP-%d-', now()->year);

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

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'primary_contact_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subjectable', 'subject_type', 'subject_id');
    }

    /**
     * Role + ownership scoping (ADR-0004): salesrep own, manager team, admin all.
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
                ->orWhereRaw('LOWER(reference) LIKE ?', [$like]);
        });
    }

    public function isOpen(): bool
    {
        return $this->stage->isOpen();
    }

    public function isWon(): bool
    {
        return $this->stage === OpportunityStage::Won;
    }

    public function isLost(): bool
    {
        return $this->stage === OpportunityStage::Lost;
    }
}
