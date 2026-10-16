<?php

namespace App\Models;

use App\Enums\DisqualificationReason;
use App\Enums\LeadRating;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Enums\Urgency;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    /**
     * Role + ownership scoping (ADR-0004): salesrep own, manager team + queue, admin all.
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

        return $query->where('owner_id', $user->id);
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
}
