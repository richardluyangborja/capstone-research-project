<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\CompanySize;
use App\Enums\CompanyStatus;
use App\Enums\CompanyTier;
use App\Enums\ContractStatus;
use App\Enums\HandoffStatus;
use App\Enums\OpportunityStage;
use App\Enums\Role;
use App\Events\CompanyActivated;
use App\Events\CompanyChurned;
use App\Events\CompanyStatusChanged;
use App\Integrations\HandoffResult;
use App\Integrations\ServiceHandoff;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        'churn_reason',
        'churned_at',
        'handoff_status',
        'handoff_reference',
        'handoff_error',
        'handoff_requested_at',
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
            'churned_at' => 'datetime',
            'handoff_status' => HandoffStatus::class,
            'handoff_requested_at' => 'datetime',
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

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * The live engagement, if any — one client works with several crews over time, but
     * only a signed contract is active (spec 09 §1, §6).
     */
    public function activeContract(): HasOne
    {
        return $this->hasOne(Contract::class)->where('status', ContractStatus::Signed->value)->latestOfMany();
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
     * Advance the client lifecycle (spec 09 §1, §5). Illegal moves raise a 422 validation
     * error; every change is recorded on the company timeline via `CompanyStatusChanged`,
     * and churn additionally records the reason + date and fires `CompanyChurned`.
     */
    public function transitionTo(
        CompanyStatus $status,
        ?User $actor = null,
        ?string $reason = null,
        ?string $note = null,
    ): void {
        if (! $this->status->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'status' => [sprintf(
                    'Cannot change the client lifecycle from %s to %s.',
                    $this->status->value,
                    $status->value,
                )],
            ]);
        }

        $from = $this->status;

        DB::transaction(function () use ($from, $status, $actor, $reason, $note): void {
            $this->status = $status;

            // The first move past `prospect` stamps the client date; reactivation keeps it.
            $this->client_since ??= now()->toDateString();

            if ($status->requiresReason()) {
                $this->churn_reason = $reason;
                $this->churned_at = now();
            } else {
                $this->churn_reason = null;
                $this->churned_at = null;
            }

            $this->save();

            CompanyStatusChanged::dispatch($this, $from, $status, $actor, $note ?? $reason);

            if ($status->isChurned()) {
                CompanyChurned::dispatch($this, $reason, $actor);
            }

            if ($status->isActive()) {
                CompanyActivated::dispatch($this, null, $actor);
            }
        });
    }

    public function hasActiveContract(): bool
    {
        return $this->contracts()->where('status', ContractStatus::Signed->value)->exists();
    }

    /**
     * Hand a signed contract to the Service department (mocked, ADR-0005) and move the
     * client accordingly (spec 09 §1–2). A handoff failure never rolls the contract back:
     * the client waits in `onboarding` with `handoff_status = failed` until it is retried.
     *
     * Called by the `ContractSigned` listener and by the handoff retry endpoint, so both
     * paths run the exact same orchestration.
     */
    public function handoffToService(Contract $contract, ?User $actor = null): void
    {
        $result = $this->requestHandoff($contract);

        DB::transaction(function () use ($contract, $result, $actor): void {
            $previous = $this->status;

            // The first signature stamps the client date; reactivation keeps the original.
            $this->client_since ??= $contract->signed_at?->toDateString() ?? now()->toDateString();
            $this->handoff_status = $result->success ? HandoffStatus::Sent : HandoffStatus::Failed;
            $this->handoff_reference = $result->reference;
            $this->handoff_error = $result->message;
            $this->handoff_requested_at = now();

            // Repeat business never resets an established client (spec 09 §3): only a
            // prospect/onboarding account advances, and a failure only parks a prospect.
            $advance = $result->success
                ? in_array($previous, [CompanyStatus::Prospect, CompanyStatus::Onboarding], true)
                : $previous === CompanyStatus::Prospect;

            if ($advance) {
                $this->status = $result->success ? CompanyStatus::Active : CompanyStatus::Onboarding;
            }

            $this->save();

            $this->activities()->create([
                'type' => ActivityType::System,
                'subject' => $result->success
                    ? sprintf('Contract %s signed — handed off to Service', $contract->reference)
                    : sprintf('Service handoff failed for contract %s', $contract->reference),
                'body' => $result->success
                    ? sprintf('Handoff reference %s.', $result->reference)
                    : $result->message,
                'status' => ActivityStatus::Done,
                'owner_id' => $actor?->id ?? $this->owner_id,
                'occurred_at' => now(),
            ]);

            if ($this->status !== $previous) {
                CompanyStatusChanged::dispatch(
                    $this,
                    $previous,
                    $this->status,
                    $actor,
                    sprintf('Signed contract %s.', $contract->reference),
                );
            }

            if ($result->success && $this->status !== $previous) {
                CompanyActivated::dispatch($this, $contract, $actor);
            }
        });
    }

    /**
     * Ask Service to take the engagement over. Driver exceptions are reported and treated
     * as a failed handoff — a broken integration must not take the contract down with it.
     */
    private function requestHandoff(Contract $contract): HandoffResult
    {
        try {
            return app(ServiceHandoff::class)->handoff($this, $contract);
        } catch (Throwable $exception) {
            report($exception);

            return HandoffResult::failed('The Service handoff failed: '.$exception->getMessage());
        }
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
     * Reasons a soft delete is not allowed (spec 06 §6). Open deals and active (signed)
     * contracts both block a delete — a client may not be archived out from under live work.
     *
     * @return array<int, string>
     */
    public function deletionBlockers(): array
    {
        $open = $this->opportunities()
            ->whereNotIn('stage', [OpportunityStage::Won->value, OpportunityStage::Lost->value])
            ->count();

        $active = $this->contracts()
            ->where('status', ContractStatus::Signed->value)
            ->count();

        $blockers = [];

        if ($open > 0) {
            $blockers[] = sprintf('This company still has %d open %s.', $open, str('opportunity')->plural($open));
        }

        if ($active > 0) {
            $blockers[] = sprintf('This company still has %d active %s.', $active, str('contract')->plural($active));
        }

        return $blockers;
    }

    public function isDeletable(): bool
    {
        return $this->deletionBlockers() === [];
    }
}
