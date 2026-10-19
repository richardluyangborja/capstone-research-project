<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\FollowUpDueState;
use App\Events\FollowUpDue;
use App\Models\Concerns\HasSubjectRecord;
use Carbon\CarbonInterface;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The lightweight quick log: a touch that happened, or — since module 04 — a **task** that is
 * owed (`type = task` with a `due_at` and an `open`/`done`/`cancelled` status, spec 12).
 *
 * A task is the one activity that is not a record of something done, so it is the one that
 * appears in `GET /follow-ups`. It is visible with its subject and owes to its owner; a
 * detailed, channel-aware history entry is a `Communication` (ADR-0017 §1).
 */
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory, HasSubjectRecord, HasUlids;

    protected $fillable = [
        'type',
        'subject',
        'body',
        'status',
        'due_at',
        'occurred_at',
        'reminded_at',
        'owner_id',
        'subject_type',
        'subject_id',
        'communication_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'status' => ActivityStatus::class,
            'due_at' => 'datetime',
            'occurred_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /**
     * The record this activity belongs to (lead / company / contact / opportunity / contract).
     * Named `subjectable` so it does not clash with the `subject` title column.
     */
    public function subjectable(): MorphTo
    {
        return $this->morphTo('subjectable', 'subject_type', 'subject_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Follow-up tasks only — the `/tasks` and `/follow-ups` rows (spec 12 §S1).
     */
    public function scopeTasks(Builder $query): Builder
    {
        return $query->where('type', ActivityType::Task);
    }

    public function isTask(): bool
    {
        return $this->type === ActivityType::Task;
    }

    /**
     * How this task's due moment reads now, or `null` for an activity with no due time.
     */
    public function dueState(?CarbonInterface $now = null): ?FollowUpDueState
    {
        return FollowUpDueState::for($this->due_at, $now);
    }

    /**
     * The tasks the due run still has to flag (module 04 slice S3, spec 12 §3, §10): open, past
     * their due moment and never reminded. A settled task is never flagged, and a reschedule
     * clears the marker so the task re-arms.
     */
    public function scopeDueForReminder(Builder $query, ?CarbonInterface $now = null): Builder
    {
        return $query->tasks()
            ->where('status', ActivityStatus::Open)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now ?? now())
            ->whereNull('reminded_at');
    }

    /**
     * Flag every open task that has fallen due and has not been flagged yet (module 04 slice S3,
     * spec 12 §10). Each one raises `FollowUpDue` — the notification is the listener's job, not
     * the run's — and is stamped as it goes, so a second run of the same day raises nothing and a
     * task that falls due later is picked up by the next one.
     *
     * Nothing runs on a timer in this phase: an admin runs this in-app or through
     * `follow-ups:notify-due`, exactly as the due sends are dispatched (rule 6).
     *
     * @return array{notified: int}
     */
    public static function notifyDue(?CarbonInterface $now = null): array
    {
        $due = static::query()
            ->dueForReminder($now)
            ->with('owner')
            ->orderBy('due_at')
            ->get();

        foreach ($due as $task) {
            FollowUpDue::dispatch($task);

            $task->forceFill(['reminded_at' => $now ?? now()])->save();
        }

        return ['notified' => $due->count()];
    }

    /**
     * Whether the task still owes something: an open task is what the worklist and the due run
     * care about (spec 12 §S1, §S3).
     */
    public function isOpenTask(): bool
    {
        return $this->isTask() && $this->status === ActivityStatus::Open;
    }

    /**
     * The order every record timeline reads in: what is still owed first (a task has not
     * happened, so it carries no `occurred_at`), then what happened, newest first.
     *
     * Where a null lands is written out rather than left to the database, because Postgres and
     * SQLite disagree about where NULLs sort in a descending feed — and the demo would then
     * disagree with the tests.
     */
    public function scopeTimelineOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('occurred_at IS NULL DESC')
            ->orderByRaw('CASE WHEN occurred_at IS NULL THEN due_at END ASC')
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Free-text match over what a person would recognise a task by (spec 12 §S1).
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->whereRaw('LOWER(subject) LIKE ?', [$like])
                ->orWhereRaw('LOWER(body) LIKE ?', [$like]);
        });
    }
}
