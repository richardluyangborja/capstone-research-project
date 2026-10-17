<?php

namespace App\Models;

use App\Enums\LeadSource;
use App\Enums\LostReason;
use App\Enums\OpportunityStage;
use App\Enums\Role;
use App\Events\OpportunityLost;
use App\Events\OpportunityStageChanged;
use App\Events\OpportunityWon;
use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A deal on a company (spec 08). Opportunities are opened by lead→client conversion or
 * created directly on an account, and carry the staffing pipeline (ADR-0002, module 02 /
 * ADR-0015): the board, stage moves, won/lost and the deal timeline.
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
        'next_step',
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
            'stage_changed_at' => 'datetime',
            'last_activity_at' => 'datetime',
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

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Deals still in play — everything the board shows as a column (module 02).
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('stage', [OpportunityStage::Won->value, OpportunityStage::Lost->value]);
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
                ->orWhereRaw('LOWER(reference) LIKE ?', [$like])
                ->orWhereHas('company', fn (Builder $company) => $company->whereRaw('LOWER(name) LIKE ?', [$like]));
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

    /**
     * Apply a legal stage move (module 02, ADR-0015 §1). Illegal moves and a `lost`
     * without a reason raise a 422. Every move stamps `stage_changed_at`, refreshes
     * `last_activity_at`, re-defaults the probability and records a `system` activity
     * through `OpportunityStageChanged`. `won`/`lost` stamp the close date; reopening
     * clears the close date and the lost reason. A `won` deals **prompts** contract
     * creation — it never runs the Service handoff (ADR-0015 §4).
     */
    public function transitionTo(
        OpportunityStage $stage,
        ?User $actor = null,
        ?LostReason $lostReason = null,
        ?int $probability = null,
        ?string $note = null,
    ): void {
        if (! $this->stage->canTransitionTo($stage)) {
            throw ValidationException::withMessages([
                'stage' => [sprintf('Cannot move a deal from %s to %s.', $this->stage->value, $stage->value)],
            ]);
        }

        if ($stage === OpportunityStage::Lost && $lostReason === null) {
            throw ValidationException::withMessages([
                'lost_reason' => ['A lost deal needs a reason.'],
            ]);
        }

        $from = $this->stage;

        DB::transaction(function () use ($from, $stage, $actor, $lostReason, $probability, $note): void {
            $this->stage = $stage;
            $this->stage_changed_at = now();
            $this->last_activity_at = now();
            $this->probability = $probability ?? $stage->defaultProbability();
            $this->actual_close_date = $stage->isTerminal() ? now()->toDateString() : null;
            $this->lost_reason = $stage === OpportunityStage::Lost ? $lostReason : null;
            $this->save();

            OpportunityStageChanged::dispatch($this, $from, $stage, $actor, $note);

            if ($stage === OpportunityStage::Won) {
                OpportunityWon::dispatch($this, $actor);
            } elseif ($stage === OpportunityStage::Lost) {
                OpportunityLost::dispatch($this, $lostReason, $actor);
            }
        });
    }

    /**
     * Days the deal has sat in its current stage (module 02 §UI).
     */
    public function daysInStage(): int
    {
        $since = $this->stage_changed_at ?? $this->created_at;

        return (int) $since?->diffInDays(now());
    }

    /**
     * Days since the last recorded touch, falling back to the stage change and capture.
     */
    public function daysSinceActivity(): int
    {
        $since = $this->last_activity_at ?? $this->stage_changed_at ?? $this->created_at;

        return (int) $since?->diffInDays(now());
    }

    /**
     * A read-time flag from `config/crm.php` (module 02). Closed deals never go stale.
     */
    public function isStale(): bool
    {
        if ($this->stage->isTerminal()) {
            return false;
        }

        return $this->daysSinceActivity() >= (int) config('crm.pipeline.stuck_days');
    }

    /**
     * Forecast weight of the deal: value × probability (module 02 §forecast).
     */
    public function weightedValue(): float
    {
        return round((float) ($this->amount ?? 0) * ((int) ($this->probability ?? 0)) / 100, 2);
    }

    /**
     * Expected-close window filter for the list/board (module 02 §API).
     */
    public function scopeCloseWindow(Builder $query, string $window): Builder
    {
        return match ($window) {
            'overdue' => $query->whereNotNull('expected_close_date')
                ->whereDate('expected_close_date', '<', now()->toDateString()),
            'month' => $query->whereBetween('expected_close_date', [now()->startOfMonth(), now()->endOfMonth()]),
            'quarter' => $query->whereBetween('expected_close_date', [now()->startOfQuarter(), now()->endOfQuarter()]),
            default => $query,
        };
    }
}
