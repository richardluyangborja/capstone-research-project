<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use App\Enums\AgingLevel;
use App\Enums\DisqualificationReason;
use App\Enums\LeadRating;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Enums\Urgency;
use App\Events\LeadStatusChanged;
use Carbon\Carbon;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'company_name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'contact_position',
        'source',
        'source_detail',
        'industry',
        'location',
        'service_interest',
        'estimated_headcount',
        'needed_roles',
        'target_start_date',
        'budget_rate',
        'authority_confirmed',
        'work_site',
        'urgency',
        'rating',
        'score',
        'qualification_notes',
        'disqualification_reason',
        'notes',
        'status',
        'owner_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'status' => LeadStatus::class,
            'rating' => LeadRating::class,
            'urgency' => Urgency::class,
            'disqualification_reason' => DisqualificationReason::class,
            'target_start_date' => 'date',
            'budget_rate' => 'decimal:2',
            'authority_confirmed' => 'boolean',
            'status_changed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Lead $lead): void {
            $lead->reference ??= static::nextReference();
            $lead->status ??= LeadStatus::New;
        });
    }

    /**
     * Human-readable reference (display/search only — never a route key, see ADR-0007).
     */
    public static function nextReference(): string
    {
        $prefix = sprintf('LD-%d-', now()->year);

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

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subjectable', 'subject_type', 'subject_id');
    }

    /**
     * The detailed communication history for this lead (module 03, spec 11).
     */
    public function communications(): MorphMany
    {
        return $this->morphMany(Communication::class, 'subjectable', 'subject_type', 'subject_id');
    }

    /**
     * Role + ownership scoping (ADR-0004): salesrep own, manager team + queue, admin all.
     *
     * The unassigned queue is a shared, claimable pool, so it is visible to every role
     * (ADR-0004 clarification — see submodule 03).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->isAdmin()) {
            return $query;
        }

        if ($user->role === Role::Manager) {
            return $query->where(function (Builder $query) use ($user): void {
                $query->whereHas('owner', fn (Builder $q) => $q->where('team_id', $user->team_id))
                    ->orWhereNull('owner_id');
            });
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('owner_id', $user->id)->orWhereNull('owner_id');
        });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->whereRaw('LOWER(company_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(contact_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(contact_email) LIKE ?', [$like])
                ->orWhereRaw('LOWER(contact_phone) LIKE ?', [$like])
                ->orWhereRaw('LOWER(reference) LIKE ?', [$like]);
        });
    }

    /**
     * Minimum criteria for `qualified` (spec 02 §2): headcount + roles + a contact channel.
     *
     * @return array<int, string>
     */
    public function missingQualificationCriteria(): array
    {
        $missing = [];

        if (blank($this->estimated_headcount)) {
            $missing[] = 'estimated_headcount';
        }

        if (blank($this->needed_roles)) {
            $missing[] = 'needed_roles';
        }

        if (blank($this->contact_email) && blank($this->contact_phone)) {
            $missing[] = 'contact';
        }

        return $missing;
    }

    public function meetsQualificationCriteria(): bool
    {
        return $this->missingQualificationCriteria() === [];
    }

    /**
     * Weighted checklist completeness, 0–100 (spec 02 §3).
     * Weights: headcount 20, roles 20, contact 10, budget 20, authority 15, site 10, start date 5.
     */
    public function qualificationScore(): int
    {
        $score = 0;

        $score += filled($this->estimated_headcount) ? 20 : 0;
        $score += filled($this->needed_roles) ? 20 : 0;
        $score += (filled($this->contact_email) || filled($this->contact_phone)) ? 10 : 0;
        $score += filled($this->budget_rate) ? 20 : 0;
        $score += $this->authority_confirmed ? 15 : 0;
        $score += filled($this->work_site) ? 10 : 0;
        $score += filled($this->target_start_date) ? 5 : 0;

        return $score;
    }

    /**
     * Derive Hot/Warm/Cold from the checklist score when no explicit rating is given.
     */
    public function deriveRating(): LeadRating
    {
        $score = $this->score ?? $this->qualificationScore();

        return match (true) {
            $score >= 75 => LeadRating::Hot,
            $score >= 45 => LeadRating::Warm,
            default => LeadRating::Cold,
        };
    }

    /**
     * Qualification fields are editable only in these states (spec 02 §6).
     */
    public function isQualifiable(): bool
    {
        return in_array($this->status, [
            LeadStatus::New,
            LeadStatus::Contacted,
            LeadStatus::Qualified,
            LeadStatus::Nurture,
        ], true);
    }

    /**
     * Apply a legal lifecycle transition (spec 04 §1–2).
     *
     * Illegal transitions raise a 422 validation error. Every change stamps
     * `status_changed_at`, refreshes `last_activity_at` (so aging restarts), and
     * records a `system` activity through the LeadStatusChanged event.
     */
    public function transitionTo(LeadStatus $status, ?User $actor = null, ?string $note = null): void
    {
        if (! $this->status->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'status' => [sprintf('Cannot change status from %s to %s.', $this->status->value, $status->value)],
            ]);
        }

        // The status machine cannot bypass the submodule 02 qualification gate.
        if ($status === LeadStatus::Qualified) {
            $missing = $this->missingQualificationCriteria();

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'criteria' => ['Lead does not meet minimum qualification criteria: '.implode(', ', $missing).'.'],
                ]);
            }

            $this->score ??= $this->qualificationScore();
            $this->rating ??= $this->deriveRating();
        }

        $from = $this->status;

        DB::transaction(function () use ($from, $status, $actor, $note): void {
            $this->status = $status;
            $this->status_changed_at = now();
            $this->last_activity_at = now();
            $this->save();

            LeadStatusChanged::dispatch($this, $from, $status, $actor, $note);
        });
    }

    /**
     * Keep `next_follow_up_at` pointing at the lead's earliest open follow-up (spec 12 §12), or
     * null when nothing is owed. Called whenever a follow-up on this lead is created, moved or
     * finished, so the lead list and the aging views read one column instead of joining two
     * tables.
     *
     * Slice S1 knows one shape — an open task; slice S2 adds the scheduled messages and takes
     * the sooner of the two, which is when this becomes the full rule.
     */
    public function syncNextFollowUpAt(): void
    {
        $nextTask = $this->activities()
            ->tasks()
            ->where('status', ActivityStatus::Open)
            ->whereNotNull('due_at')
            ->min('due_at');

        $this->forceFill([
            'next_follow_up_at' => $nextTask === null ? null : Carbon::parse($nextTask),
        ])->save();
    }

    /**
     * Days since the last recorded activity, falling back to the status change and
     * finally to capture (spec 04 §3).
     */
    public function daysSinceActivity(): int
    {
        $since = $this->last_activity_at ?? $this->status_changed_at ?? $this->created_at;

        return (int) $since?->diffInDays(now());
    }

    /**
     * Aging bucket from `config/crm.php` (spec 04 §3). Terminal/archived leads never age.
     */
    public function agingLevel(): ?AgingLevel
    {
        if ($this->status->isTerminal() || $this->status === LeadStatus::Archived) {
            return null;
        }

        $days = $this->daysSinceActivity();

        return match (true) {
            $days >= (int) config('crm.aging.archive_days') => AgingLevel::Archive,
            $days >= (int) config('crm.aging.at_risk_days') => AgingLevel::AtRisk,
            $days >= (int) config('crm.aging.stale_days') => AgingLevel::Stale,
            default => null,
        };
    }

    /**
     * Non-terminal, non-archived leads with no activity for the given threshold (spec 04 §3).
     * Accepts a specific level (`stale` / `at_risk` / `archive`) or any truthy value for `stale`.
     */
    public function scopeStale(Builder $query, bool|string $level = true): Builder
    {
        $days = match ($level) {
            'archive' => (int) config('crm.aging.archive_days'),
            'at_risk' => (int) config('crm.aging.at_risk_days'),
            default => (int) config('crm.aging.stale_days'),
        };

        return $query
            ->whereNotIn('status', [LeadStatus::Converted->value, LeadStatus::Archived->value])
            ->whereRaw(
                'COALESCE(last_activity_at, status_changed_at, created_at) <= ?',
                [now()->subDays($days)->toDateTimeString()],
            );
    }
}
