<?php

namespace App\Models;

use App\Enums\DiscrepancyCategory;
use App\Enums\DiscrepancyStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One disputed figure a rep escalated to their manager (module 09 slice T4, spec 21 §T4, ADR-0033).
 *
 * The CRM records the disagreement; it never corrects Core 2. `open` renders as an amber
 * "Under Review" pill in the employee profile, and a manager or admin settles it with
 * `markResolved()`. Rows are history: nothing deletes them, and a second resolve is refused.
 *
 * @property string $id
 */
class EmployeeDiscrepancy extends Model
{
    use HasUlids;

    /**
     * A flag starts its life under review; the DB default carries the same value, but setting it
     * here means the instance returned by `create()` already reports its state.
     */
    protected $attributes = ['status' => 'open'];

    protected $fillable = [
        'employee_id',
        'raised_by',
        'category',
        'explanation',
        'subject_type',
        'subject_id',
        'status',
        'resolved_at',
        'resolved_by',
        'resolution_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => DiscrepancyCategory::class,
            'status' => DiscrepancyStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * The opportunity or contract the flag is about, when it names one. The relation name matches
     * the method so `load('subjectable')` and the resource agree on one key.
     */
    public function subjectable(): MorphTo
    {
        return $this->morphTo('subjectable', 'subject_type', 'subject_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', DiscrepancyStatus::Open->value);
    }

    public function isResolved(): bool
    {
        return $this->status === DiscrepancyStatus::Resolved;
    }

    /**
     * Settle the flag. The caller decides who may do this and refuses a second resolve; the row
     * keeps its original category and explanation as the record of what was disputed.
     */
    public function markResolved(User $resolver, ?string $note): void
    {
        $this->forceFill([
            'status' => DiscrepancyStatus::Resolved,
            'resolved_at' => now(),
            'resolved_by' => $resolver->id,
            'resolution_note' => $note,
        ])->save();
    }
}
