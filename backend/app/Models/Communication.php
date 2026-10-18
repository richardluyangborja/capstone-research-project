<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationOutcome;
use App\Enums\CommunicationStatus;
use Database\Factories\CommunicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The detailed communication history entry (module 03, spec 11, ADR-0017).
 *
 * `Activity` remains the lightweight quick log; this is the channel-aware record — direction,
 * participants, body, duration, outcome. Logging one also writes an activity mirror (see
 * `RecordCommunicationActivity`) so the existing timelines stay the merged feed.
 */
class Communication extends Model
{
    /** @use HasFactory<CommunicationFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'contact_id',
        'channel',
        'direction',
        'status',
        'subject',
        'body',
        'from_name',
        'from_address',
        'to_name',
        'to_address',
        'occurred_at',
        'duration_minutes',
        'outcome',
        'external_id',
        'failed_reason',
        'thread_id',
        'in_reply_to_id',
        'owner_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'direction' => CommunicationDirection::class,
            'status' => CommunicationStatus::class,
            'outcome' => CommunicationOutcome::class,
            'occurred_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Communication $communication): void {
            $communication->reference ??= static::nextReference();
            $communication->occurred_at ??= now();

            // An inbound message has been received; an electronic outbound one sent; a call
            // or a meeting is simply logged (spec 11 §Business rules).
            if ($communication->status === null && $communication->channel !== null && $communication->direction !== null) {
                $communication->status = CommunicationStatus::defaultFor($communication->channel, $communication->direction);
            }
        });

        // The mirror is a pointer, not a record of its own: it goes when the detail does, and
        // is rewritten if the entry is restored (ADR-0017 §2).
        static::deleting(function (Communication $communication): void {
            $communication->activities()->delete();
        });

        static::restored(function (Communication $communication): void {
            $communication->syncActivityMirror();
        });
    }

    /**
     * Human-readable reference (display/search only — never a route key, see ADR-0007).
     */
    public static function nextReference(): string
    {
        $prefix = sprintf('COM-%d-', now()->year);

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->lockForUpdate()
            ->value('reference');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * The record this message belongs to (lead / company / contact / opportunity / contract).
     * Named `subjectable` so it does not clash with the `subject` title column.
     */
    public function subjectable(): MorphTo
    {
        return $this->morphTo('subjectable', 'subject_type', 'subject_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The activity mirror written on the subject's timeline (ADR-0017 §2).
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Write — or refresh — the timeline mirror for this message (ADR-0017 §2). The mirror is
     * a pointer: it carries the title, the channel's activity type and the subject's morph
     * columns, never a copy of the body, so this record stays the single source of truth.
     */
    public function syncActivityMirror(): void
    {
        $attributes = [
            'type' => $this->channel->activityType(),
            'subject' => $this->subject,
            'status' => ActivityStatus::Done,
            'occurred_at' => $this->occurred_at,
            'owner_id' => $this->owner_id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
        ];

        $mirror = $this->activities()->first();

        if ($mirror === null) {
            $this->activities()->create($attributes);

            return;
        }

        $mirror->update($attributes);
    }

    /**
     * A message is visible if and only if its subject is visible (ADR-0017 §5) — a history
     * entry never outlives the visibility of the record it belongs to.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $subjects = [
            Lead::class => Lead::query()->visibleTo($user),
            Company::class => Company::query()->visibleTo($user),
            Contact::class => Contact::query()->visibleTo($user),
            Opportunity::class => Opportunity::query()->visibleTo($user),
            Contract::class => Contract::query()->visibleTo($user),
        ];

        return $query->where(function (Builder $query) use ($subjects): void {
            foreach ($subjects as $type => $subjectQuery) {
                $query->orWhere(function (Builder $query) use ($type, $subjectQuery): void {
                    $query->where('subject_type', $type)
                        ->whereIn('subject_id', $subjectQuery->select('id'));
                });
            }
        });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->whereRaw('LOWER(reference) LIKE ?', [$like])
                ->orWhereRaw('LOWER(subject) LIKE ?', [$like])
                ->orWhereRaw('LOWER(body) LIKE ?', [$like])
                ->orWhereRaw('LOWER(from_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(from_address) LIKE ?', [$like])
                ->orWhereRaw('LOWER(to_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(to_address) LIKE ?', [$like]);
        });
    }

    /**
     * Only a draft or a logged-after-the-fact record may be edited; a delivered or received
     * message is a record of what was said (ADR-0017 §6).
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * The short record types a message can hang off, mapped to their model (spec 11 §Payloads).
     * The type is what the API accepts and returns; the class is what the morph column stores.
     *
     * @return array<string, class-string<Model>>
     */
    public static function subjectTypes(): array
    {
        return [
            'lead' => Lead::class,
            'company' => Company::class,
            'contact' => Contact::class,
            'opportunity' => Opportunity::class,
            'contract' => Contract::class,
        ];
    }

    /**
     * The short record type used by the UI to build a link (`lead`, `company`, …).
     */
    public function recordType(): ?string
    {
        $type = array_search($this->subject_type, self::subjectTypes(), true);

        return $type === false ? null : $type;
    }

    /**
     * A human label for the record this message hangs off.
     */
    public function recordLabel(): ?string
    {
        $subject = $this->subjectable;

        return match (true) {
            $subject instanceof Lead => $subject->company_name,
            $subject instanceof Company => $subject->name,
            $subject instanceof Contact => $subject->name,
            $subject instanceof Opportunity => $subject->name,
            $subject instanceof Contract => $subject->title,
            default => null,
        };
    }
}
