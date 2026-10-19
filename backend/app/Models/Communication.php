<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationOutcome;
use App\Enums\CommunicationStatus;
use App\Events\CommunicationFailed;
use App\Events\CommunicationLogged;
use App\Events\CommunicationSent;
use App\Integrations\EmailGateway;
use App\Integrations\OutboundEmail;
use App\Models\Concerns\HasSubjectRecord;
use Database\Factories\CommunicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

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
    use HasFactory, HasSubjectRecord, HasUlids, SoftDeletes;

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

        // A retired message keeps its files — it is still readable (S3) — but a message that is
        // destroyed outright takes them with it, so no file outlives the row that justified it.
        static::forceDeleting(function (Communication $communication): void {
            $communication->attachments()->get()->each(
                fn (Attachment $attachment) => $attachment->delete(),
            );
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
     * The files that came with this message (module 03 slice S3) — the polymorphic table
     * `00-data-model.md` deferred, which communications are the first to use.
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * The message this one answers (module 03 slice S4) — null for a message nobody replied
     * to. The direct parent only: the conversation itself is selected by `thread_id`. A
     * retired parent is still named, because a retired message is still readable.
     */
    public function inReplyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'in_reply_to_id')->withTrashed();
    }

    /**
     * Write — or refresh — the timeline mirror for this message (ADR-0017 §2). The mirror is
     * a pointer: it carries the title, the channel's activity type and the subject's morph
     * columns, never a copy of the body, so this record stays the single source of truth.
     */
    public function syncActivityMirror(): void
    {
        // A draft has not happened yet, so it has no place on the record's timeline (S2).
        if ($this->status === CommunicationStatus::Draft) {
            $this->activities()->delete();

            return;
        }

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
     * Free-text match over the fields a person would recognise a message by.
     */
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
     * Whether the files on this message may still change (module 03 slice S3). Files are part
     * of the record of what was said, so they freeze once the message has left or arrived —
     * but a rejected send never went anywhere, so it is still the rep's to fix before retrying.
     */
    public function allowsAttachmentChanges(): bool
    {
        return $this->isEditable() || $this->status === CommunicationStatus::Failed;
    }

    /**
     * Why this message keeps the files it carried, or `null` while they may still change. The
     * upload and the removal both raise this as a 422 (spec 11 §S3).
     */
    public function attachmentLockReason(): ?string
    {
        return $this->allowsAttachmentChanges()
            ? null
            : sprintf('A %s message keeps the files it carried.', $this->status->value);
    }

    /**
     * Whether this message is part of a conversation yet (module 03 slice S4). A message
     * nobody has answered carries no `thread_id`.
     */
    public function belongsToThread(): bool
    {
        return $this->thread_id !== null;
    }

    /**
     * Open the conversation this message belongs to (slice S4): a standalone message becomes
     * the root of its own thread, one that is already threaded is left untouched, and either
     * way the thread's id comes back.
     *
     * The root carries its own id as `thread_id`, so a single `where thread_id = ?` selects
     * the whole conversation — the original included — and nothing but an index column on an
     * already-recorded message is ever rewritten.
     */
    public function openThread(): string
    {
        if ($this->thread_id === null) {
            $this->thread_id = $this->id;
            $this->save();
        }

        return $this->thread_id;
    }

    /**
     * Whether this message has actually happened: someone wrote to us, we sent it, or it was
     * logged after the fact. A `draft` has not left, a `scheduled` follow-up has not happened
     * yet (module 04), and a rejected send never went anywhere — so nothing can answer them
     * (slice S4).
     */
    public function hasHappened(): bool
    {
        return ! in_array(
            $this->status,
            [CommunicationStatus::Draft, CommunicationStatus::Scheduled, CommunicationStatus::Failed],
            true,
        );
    }

    /**
     * Why this message cannot be answered, or `null` while it can. The reply endpoint raises
     * this as a 422 (spec 11 §S4) — the same shape as the file lock above.
     */
    public function replyLockReason(): ?string
    {
        return $this->hasHappened()
            ? null
            : sprintf('A %s message cannot be answered — it never went out.', $this->status->value);
    }

    /**
     * Whether this message could go out *now*: an outbound email still in its draft, or one
     * the gateway rejected and the rep can try again (spec 11 §S2). Whether the caller may
     * send it is the policy's question, not this one.
     */
    public function isSendable(): bool
    {
        return $this->channel === CommunicationChannel::Email
            && $this->direction === CommunicationDirection::Outbound
            && in_array($this->status, [CommunicationStatus::Draft, CommunicationStatus::Failed], true);
    }

    /**
     * Hand this message to the email gateway and record what came back (spec 11 §S2,
     * ADR-0017 §4). A rejected send is not an exception: the record is parked as `failed`
     * with the gateway's reason, so the rep can read it and retry — and the timeline still
     * learns about the attempt.
     *
     * The message "happened" when it left, so a send (or a successful retry) stamps
     * `occurred_at` too.
     */
    public function sendThrough(EmailGateway $gateway): self
    {
        $result = $gateway->send(OutboundEmail::forCommunication($this));

        return DB::transaction(function () use ($result): self {
            $this->status = $result->success ? CommunicationStatus::Sent : CommunicationStatus::Failed;
            $this->external_id = $result->externalId;
            $this->failed_reason = $result->message;
            $this->occurred_at = now();
            $this->save();

            if ($result->success) {
                CommunicationSent::dispatch($this);
            } else {
                CommunicationFailed::dispatch($this);
            }

            return $this;
        });
    }

    /**
     * Fall back to the linked contact's address when no recipient was typed: the composer
     * lets a rep pick a person instead of an address, and a send needs a destination.
     */
    public function fillRecipientFromContact(): void
    {
        if ($this->to_address !== null && $this->to_address !== '') {
            return;
        }

        $this->to_address = $this->contact?->email;
        $this->to_name ??= $this->contact?->name;
    }

    /**
     * Pull the mocked mailbox's replies into the history (module 03 slice S2, spec 11
     * §Business rules 7). Inbound is never pushed and nothing runs on a timer in this phase,
     * so both the artisan command and the demo action call this.
     *
     * A reply is recorded against the record its original message belongs to, is skipped if it
     * was already pulled (its external id is the key), and is dropped when it answers a message
     * we do not have — there are no orphan messages (spec 11 §Business rules 1).
     *
     * @return int the number of replies recorded
     */
    public static function pullInbound(EmailGateway $gateway): int
    {
        $sent = static::query()
            ->where('channel', CommunicationChannel::Email)
            ->where('direction', CommunicationDirection::Outbound)
            ->where('status', CommunicationStatus::Sent)
            ->orderByDesc('occurred_at')
            ->limit(25)
            ->get();

        $byExternalId = $sent->keyBy(fn (self $communication): string => (string) $communication->external_id);

        $replies = $gateway->received(
            $sent->map(fn (self $communication): OutboundEmail => OutboundEmail::forCommunication($communication))->all(),
        );

        $recorded = 0;

        foreach ($replies as $reply) {
            $origin = $reply->inReplyTo === null || $reply->inReplyTo === ''
                ? null
                : $byExternalId->get($reply->inReplyTo);

            if (! $origin instanceof self) {
                continue;
            }

            if (static::withTrashed()->where('external_id', $reply->externalId)->exists()) {
                continue;
            }

            $subject = $origin->subjectable;

            if ($subject === null) {
                continue;
            }

            /** @var self $inbound */
            $inbound = $subject->communications()->create([
                'contact_id' => $origin->contact_id,
                'channel' => CommunicationChannel::Email,
                'direction' => CommunicationDirection::Inbound,
                'status' => CommunicationStatus::Received,
                'subject' => $reply->subject,
                'body' => $reply->body,
                'from_name' => $reply->fromName,
                'from_address' => $reply->fromAddress,
                'to_name' => $reply->toName,
                'to_address' => $reply->toAddress,
                'occurred_at' => $reply->receivedAt,
                'external_id' => $reply->externalId,
                // A pulled reply answers a message we hold, so it joins that conversation
                // (slice S4) rather than landing beside it as an unrelated message.
                'thread_id' => $origin->openThread(),
                'in_reply_to_id' => $origin->id,
                'owner_id' => $origin->owner_id,
            ]);

            CommunicationLogged::dispatch($inbound);

            $recorded++;
        }

        return $recorded;
    }
}
