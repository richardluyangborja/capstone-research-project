<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationStatus;
use App\Enums\SurveyInvitationState;
use App\Enums\SurveyInvitationStatus;
use App\Integrations\EmailGateway;
use Database\Factories\SurveyInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A survey sent to a client (module 05 slice S2, spec 13, ADR-0019 §3–5).
 *
 * The invitation is the unit that goes out: it snapshots who received it, carries the
 * single-use token the public page is keyed by, and points at the `Communication` that logged
 * the email — so the account timeline, the history book and "last contacted" all see the send
 * without knowing surveys exist.
 *
 * `state()` adds `expired` to the stored status at read time (nothing sweeps the table), and
 * `isAnswerable()` is the one rule the public page and the endpoints share.
 */
class SurveyInvitation extends Model
{
    /** @use HasFactory<SurveyInvitationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'survey_id',
        'company_id',
        'contact_id',
        'contract_id',
        'communication_id',
        'token',
        'recipient_name',
        'recipient_email',
        'message',
        'status',
        'failed_reason',
        'sent_at',
        'expires_at',
        'submitted_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SurveyInvitationStatus::class,
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SurveyInvitation $invitation): void {
            $invitation->reference ??= static::nextReference();
            // The link is the credential, so it is random — never the ULID (ADR-0019 §3).
            $invitation->token ??= Str::random(48);
            $invitation->status ??= SurveyInvitationStatus::Sent;
        });
    }

    /**
     * Human-readable reference (display/search only — never a route key, see ADR-0007).
     */
    public static function nextReference(): string
    {
        $prefix = sprintf('SRV-%d-', now()->year);

        $last = static::query()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->lockForUpdate()
            ->value('reference');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function survey(): BelongsTo
    {
        // A retired survey still names the questions its invitations asked: retiring removes it
        // from the library, not from the history of what was sent.
        return $this->belongsTo(Survey::class)->withTrashed();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * The email this invitation went out as (module 03). One invitation, one message.
     */
    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    public function response(): HasOne
    {
        return $this->hasOne(SurveyResponse::class, 'invitation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What the invitation reads as today — the stored status plus the one state time adds.
     */
    public function state(): SurveyInvitationState
    {
        return match ($this->status) {
            SurveyInvitationStatus::Submitted => SurveyInvitationState::Submitted,
            SurveyInvitationStatus::Cancelled => SurveyInvitationState::Cancelled,
            SurveyInvitationStatus::Failed => SurveyInvitationState::Failed,
            default => $this->isExpired()
                ? SurveyInvitationState::Expired
                : SurveyInvitationState::Sent,
        };
    }

    public function isExpired(): bool
    {
        return $this->status === SurveyInvitationStatus::Sent
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /**
     * Whether the client can still open the link and answer — a `sent` invitation inside its
     * window. Everything else (submitted, cancelled, failed, expired) is 410 on the public page.
     */
    public function isAnswerable(): bool
    {
        return $this->status === SurveyInvitationStatus::Sent && ! $this->isExpired();
    }

    /**
     * The public page URL. Built from `crm.surveys.public_url` — the frontend origin — so local
     * and production differ by configuration only (ADR-0019 §4). Never hardcoded.
     */
    public function publicUrl(): string
    {
        return rtrim((string) config('crm.surveys.public_url'), '/').'/survey/'.$this->token;
    }

    /**
     * Hand the invitation's email to the (mocked) gateway and record what came back. Used by
     * both the first send and a resend: the same message is re-handed, with a fresh window and
     * the expiry the body quotes kept honest.
     *
     * A gateway rejection is a delivery outcome, not an exception (spec 13 §Business rules 5):
     * the invitation is stored `failed` with its reason and can be resent.
     */
    public function deliver(EmailGateway $gateway, User $actor): self
    {
        return DB::transaction(function () use ($gateway, $actor): self {
            $this->sent_at = now();
            $this->expires_at = now()->addDays((int) config('crm.surveys.invitation_days'));

            $email = $this->emailContent($actor);

            $payload = [
                'contact_id' => $this->contact_id,
                'channel' => CommunicationChannel::Email,
                'direction' => CommunicationDirection::Outbound,
                'subject' => $email['subject'],
                'body' => $email['body'],
                'from_name' => $actor->name,
                'from_address' => 'sales@primepower.test',
                'to_name' => $this->recipient_name,
                'to_address' => $this->recipient_email,
                'owner_id' => $actor->id,
            ];

            $communication = $this->communication;

            if ($communication === null) {
                /** @var Communication $communication */
                $communication = $this->company->communications()->create(
                    $payload + ['status' => CommunicationStatus::Draft],
                );
                $this->communication()->associate($communication);
            } else {
                $communication->fill($payload)->save();
            }

            $communication->sendThrough($gateway, $actor);

            $this->status = $communication->status === CommunicationStatus::Sent
                ? SurveyInvitationStatus::Sent
                : SurveyInvitationStatus::Failed;
            $this->failed_reason = $communication->failed_reason;
            $this->save();

            return $this;
        });
    }

    /**
     * The email a client reads: a greeting, the sender's note, the survey's own description,
     * the link, and when it stops working. Dates are quoted in the business calendar.
     *
     * Exposed so the demo seeder writes the same wording a live send would (a seeded
     * communication is a real history row, not a placeholder).
     *
     * @return array{subject: string, body: string}
     */
    public function emailContent(User $actor): array
    {
        return [
            'subject' => sprintf('A quick word from Primepower — %s', $this->survey?->name ?? 'our service'),
            'body' => $this->composeBody($actor),
        ];
    }

    private function composeBody(User $actor): string
    {
        $firstName = $this->recipient_name === null
            ? null
            : Str::before(trim($this->recipient_name), ' ');

        $lines = [sprintf('Hi %s,', $firstName ?: 'there')];

        if (filled($this->message)) {
            $lines[] = (string) $this->message;
        }

        if (filled($this->survey?->description)) {
            $lines[] = (string) $this->survey->description;
        }

        $lines[] = "A few questions, and a link to answer them:\n".$this->publicUrl();

        $closes = $this->expires_at
            ?->copy()
            ->setTimezone((string) config('crm.timezone'))
            ->format('d M Y');

        $lines[] = $closes === null
            ? 'The link is yours alone.'
            : sprintf('The link is yours alone and is good until %s.', $closes);

        $lines[] = sprintf('— %s, Primepower Manpower Services', $actor->name);

        return implode("\n\n", $lines);
    }

    /**
     * The book narrows by what the client did, by whose account it is and by the survey; a
     * manager also searches across the reference, the recipient and both names.
     */
    public function scopeFilterState(Builder $query, ?string $state): Builder
    {
        $now = Carbon::now();

        return match ($state) {
            'sent' => $query->where('status', SurveyInvitationStatus::Sent->value)
                ->where(fn (Builder $query) => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now)),
            'expired' => $query->where('status', SurveyInvitationStatus::Sent->value)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now),
            'failed', 'submitted', 'cancelled' => $query->where('status', $state),
            default => $query,
        };
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->whereRaw('LOWER(reference) LIKE ?', [$like])
                ->orWhereRaw('LOWER(recipient_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(recipient_email) LIKE ?', [$like])
                ->orWhereHas('company', fn (Builder $company) => $company->whereRaw('LOWER(name) LIKE ?', [$like]))
                ->orWhereHas('survey', fn (Builder $survey) => $survey->whereRaw('LOWER(name) LIKE ?', [$like]));
        });
    }
}
