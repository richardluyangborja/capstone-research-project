<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\FollowUpDueState;
use App\Enums\FollowUpSource;
use App\Events\FollowUpAssigned;
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
        'source_key',
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
     * Raise the reminders the records imply (module 04 slice S4, spec 12 §11): a renewal task
     * for every signed contract inside its renewal window, a check-in for every client nobody
     * has contacted for a while, and a nudge for every lead that has gone stale.
     *
     * The run is **idempotent through `source_key`** — a task is added only where no task holds
     * the key yet — and it **never invents an owner**: a record nobody owns is skipped and
     * reported, as is one somebody is already working by hand, so the caller sees exactly what
     * it could not place. Every task it does create tells its owner, exactly like a hand-written
     * one.
     *
     * Nothing runs on a timer in this phase: a manager runs this in-app or through
     * `follow-ups:generate`.
     *
     * @return array{created: int, skipped: int, sources: array<int, array<string, mixed>>}
     */
    public static function generateFollowUps(?CarbonInterface $now = null): array
    {
        $now = ($now ?? now())->copy();

        $sources = [
            static::place(FollowUpSource::Renewal, static::renewalProposals($now)),
            static::place(FollowUpSource::CheckIn, static::checkInProposals($now)),
            static::place(FollowUpSource::StaleLead, static::staleLeadProposals($now)),
        ];

        return [
            'created' => array_sum(array_column($sources, 'created')),
            'skipped' => array_sum(array_column($sources, 'skipped')),
            'sources' => $sources,
        ];
    }

    /**
     * The source behind this task, or null when somebody wrote it by hand (spec 12 §11) — the
     * worklist's mark that the house raised this one.
     */
    public function source(): ?FollowUpSource
    {
        return FollowUpSource::fromKey($this->source_key);
    }

    /**
     * Every signed contract inside its renewal window (spec 12 §11).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function renewalProposals(CarbonInterface $now): array
    {
        return Contract::query()
            ->signedExpiringSoon($now)
            ->get()
            ->map(fn (Contract $contract): array => [
                'record' => ['type' => 'contract', 'id' => $contract->id, 'label' => $contract->title],
                'subject_type' => $contract->getMorphClass(),
                'subject_id' => $contract->id,
                'owner_id' => $contract->owner_id,
                'key' => FollowUpSource::Renewal->keyFor($contract->id, $now),
                'subject' => sprintf('Renew %s', $contract->title),
                'body' => sprintf(
                    '%s ends on %s — open the renewal conversation.',
                    $contract->reference,
                    $contract->end_date?->toDateString(),
                ),
                'due_at' => static::renewalDueAt($contract, $now),
            ])
            ->all();
    }

    /**
     * Every client nobody has contacted for `crm.follow_ups.check_in_days` (spec 12 §11). A
     * churned client is included on purpose — an account that has gone silent is exactly the one
     * worth a call — and one that has never been contacted at all is measured from
     * `client_since`.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function checkInProposals(CarbonInterface $now): array
    {
        $quietSince = $now->copy()->subDays((int) config('crm.follow_ups.check_in_days'));

        return Company::query()
            ->whereNotNull('client_since')
            ->get()
            ->map(function (Company $company) use ($quietSince, $now): ?array {
                $lastContacted = $company->lastContactedAt() ?? $company->client_since;

                if ($lastContacted === null || $lastContacted->greaterThan($quietSince)) {
                    return null;
                }

                return [
                    'record' => ['type' => 'company', 'id' => $company->id, 'label' => $company->name],
                    'subject_type' => $company->getMorphClass(),
                    'subject_id' => $company->id,
                    'owner_id' => $company->owner_id,
                    'key' => FollowUpSource::CheckIn->keyFor($company->id, $now),
                    'subject' => sprintf('Check in with %s', $company->name),
                    'body' => sprintf(
                        'Last contacted %s — nobody has been in touch since.',
                        $lastContacted->toDateString(),
                    ),
                    'due_at' => static::nextMorning($now),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Every lead that has gone stale (spec 12 §11, on spec 04's thresholds). The scope already
     * leaves out terminal and archived leads; what it cannot know is who owns the row, which is
     * `place()`'s business.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function staleLeadProposals(CarbonInterface $now): array
    {
        return Lead::query()
            ->stale()
            ->get()
            ->map(fn (Lead $lead): array => [
                'record' => ['type' => 'lead', 'id' => $lead->id, 'label' => $lead->company_name],
                'subject_type' => $lead->getMorphClass(),
                'subject_id' => $lead->id,
                'owner_id' => $lead->owner_id,
                'key' => FollowUpSource::StaleLead->keyFor($lead->id, $now),
                'subject' => sprintf('Follow up with %s', $lead->company_name),
                'body' => sprintf(
                    'No activity logged for %d days — chase it before it goes cold.',
                    $lead->daysSinceActivity(),
                ),
                'due_at' => static::nextMorning($now),
            ])
            ->all();
    }

    /**
     * Turn one source's proposals into tasks (spec 12 §11), reporting what was skipped and why.
     * A proposal nobody owns, and one whose record somebody is already working by hand, are both
     * left alone rather than handed to whoever happened to run the generator.
     *
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array<string, mixed>
     */
    private static function place(FollowUpSource $source, array $proposals): array
    {
        $placed = [];
        $skips = [];

        foreach ($proposals as $proposal) {
            if ($proposal['owner_id'] === null) {
                $skips[] = ['record' => $proposal['record'], 'reason' => 'The record has no owner.'];

                continue;
            }

            if (static::query()->where('source_key', $proposal['key'])->exists()) {
                $skips[] = [
                    'record' => $proposal['record'],
                    'reason' => 'A reminder from this run already stands.',
                ];

                continue;
            }

            if (static::hasHandWrittenOpenFollowUp($proposal['subject_type'], $proposal['subject_id'])) {
                $skips[] = [
                    'record' => $proposal['record'],
                    'reason' => 'Somebody already has follow-up work open on this record.',
                ];

                continue;
            }

            $task = static::create([
                'type' => ActivityType::Task,
                'subject' => $proposal['subject'],
                'body' => $proposal['body'],
                'status' => ActivityStatus::Open,
                'due_at' => $proposal['due_at'],
                'owner_id' => $proposal['owner_id'],
                'subject_type' => $proposal['subject_type'],
                'subject_id' => $proposal['subject_id'],
                'source_key' => $proposal['key'],
            ]);

            // Keep the subject's own next-follow-up column honest, exactly as a hand-written
            // task does (spec 12 §12).
            $task->syncSubjectFollowUp();
            $task->load('owner');

            if ($task->owner !== null) {
                // Nobody assigned this one — the generator did — so the owner is told (spec 12 §Events).
                FollowUpAssigned::dispatch($task, $task->owner, null);
            }

            $placed[] = [
                'id' => $task->id,
                'subject' => $task->subject,
                'record' => $proposal['record'],
                'owner' => $task->owner === null
                    ? null
                    : ['id' => $task->owner->id, 'name' => $task->owner->name],
                'due_at' => $task->due_at,
            ];
        }

        return [
            'key' => $source->value,
            'label' => $source->groupLabel(),
            'created' => count($placed),
            'skipped' => count($skips),
            'tasks' => $placed,
            'skips' => $skips,
        ];
    }

    /**
     * Whether somebody already owes work on this record by hand — an open task a person wrote,
     * or a scheduled message on it (spec 12 §11). The generator reads this so it never piles a
     * fresh reminder onto work that is already in hand; its own tasks do not block it, because
     * `source_key` is what governs those, week bucket and all.
     */
    private static function hasHandWrittenOpenFollowUp(string $subjectType, string $subjectId): bool
    {
        $openTask = static::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->tasks()
            ->where('status', ActivityStatus::Open)
            ->whereNull('source_key')
            ->exists();

        return $openTask || Communication::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->scheduled()
            ->exists();
    }

    /**
     * The moment a generated reminder proposes: the morning of the next business day, at 09:00
     * in the business calendar — the same default the create form offers (spec 12 §UI) — as the
     * UTC instant the column stores.
     */
    private static function nextMorning(CarbonInterface $now, int $daysAhead = 1): CarbonInterface
    {
        return $now->copy()
            ->setTimezone(config('crm.timezone'))
            ->addDays($daysAhead)
            ->setTime(9, 0)
            ->utc();
    }

    /**
     * A renewal is worth opening a week before the contract lapses. If that moment has already
     * gone — a contract past its end date it never renewed — it falls back to the next morning
     * rather than proposing a task in the past.
     */
    private static function renewalDueAt(Contract $contract, CarbonInterface $now): CarbonInterface
    {
        $tomorrow = static::nextMorning($now);
        $end = $contract->end_date?->copy()->setTimezone(config('crm.timezone'));

        if ($end === null) {
            return $tomorrow;
        }

        $aWeekBefore = $end->setTime(9, 0)->subDays(7)->utc();

        return $aWeekBefore->greaterThan($tomorrow) ? $aWeekBefore : $tomorrow;
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
