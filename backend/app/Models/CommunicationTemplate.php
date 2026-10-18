<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\ContactStatus;
use Carbon\Carbon;
use Database\Factories\CommunicationTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Reusable wording for the composer (module 03 slice S5, spec 11, ADR-0017 §10).
 *
 * A template is only ever a **prefill**: rendering it fills the log form, and what gets saved is
 * an ordinary `Communication` that never points back here. Editing or retiring a template
 * therefore cannot rewrite history.
 *
 * The title line and body may carry `{{merge}}` fields, resolved at render time from the record
 * the message is about and the person writing it. A placeholder the record cannot fill is left
 * **verbatim** and reported in `unresolved` — the server never invents a value.
 */
class CommunicationTemplate extends Model
{
    /** @use HasFactory<CommunicationTemplateFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'name',
        'channel',
        'subject',
        'body',
        'is_active',
        'created_by',
    ];

    /**
     * The column default is applied on insert, not on a fresh model — and the store request lets
     * `is_active` be omitted, so the response would otherwise report `null` for a template that
     * was just created active.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Who wrote it — display only. A template outlives its author.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The templates the composer may offer. A retired-from-the-picker template stays readable
     * (and renderable) — `is_active` is a picker knob, not a permission.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(subject) LIKE ?', [$like])
                ->orWhereRaw('LOWER(body) LIKE ?', [$like]);
        });
    }

    /**
     * The merge-field vocabulary, served by the API so the composer's chips and the renderer
     * cannot drift apart (spec 11 §S5).
     *
     * @return array<string, string>
     */
    public static function mergeFields(): array
    {
        return [
            'contact.name' => 'Contact name',
            'contact.first_name' => 'Contact first name',
            'contact.position' => 'Contact position',
            'contact.email' => 'Contact email',
            'company.name' => 'Account name',
            'opportunity.name' => 'Deal name',
            'contract.title' => 'Contract title',
            'contract.reference' => 'Contract reference',
            'user.name' => 'Your name',
            'user.email' => 'Your email',
            'today' => "Today's date",
        ];
    }

    /**
     * Render the title line and body against a record (spec 11 §S5).
     *
     * @return array{channel: CommunicationChannel, subject: string, body: string, unresolved: array<int, string>}
     */
    public function render(Model $subject, ?Contact $contact, User $user): array
    {
        $values = self::mergeValues($subject, $contact, $user);
        $unresolved = [];

        $fill = function (string $text) use ($values, &$unresolved): string {
            return (string) preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
                function (array $matches) use ($values, &$unresolved): string {
                    [$placeholder, $token] = $matches;
                    $value = $values[$token] ?? null;

                    // Unknown, or known but empty for this record: keep the placeholder so the
                    // rep sees the gap instead of sending a silently blank line.
                    if ($value === null || trim($value) === '') {
                        $unresolved[$token] = $token;

                        return $placeholder;
                    }

                    return $value;
                },
                $text,
            );
        };

        return [
            'channel' => $this->channel,
            'subject' => $fill($this->subject),
            'body' => $fill($this->body),
            'unresolved' => array_values($unresolved),
        ];
    }

    /**
     * What each merge token resolves to for this record — the one place the mapping lives.
     *
     * `contact.*` is "the person the message is with": the linked contact where there is one, or
     * the lead's own contact details when the record is still a lead. `company.name` follows the
     * record — a lead's `company_name`, a contact's account, a deal's or contract's account, or
     * the account itself.
     *
     * @return array<string, ?string>
     */
    public static function mergeValues(Model $subject, ?Contact $contact, User $user): array
    {
        [$name, $position, $email] = match (true) {
            $subject instanceof Contact => [$subject->name, $subject->position, $subject->email],
            $subject instanceof Company => self::personFrom($contact ?? self::primaryContactOf($subject)),
            $subject instanceof Lead => $contact instanceof Contact
                ? self::personFrom($contact)
                : [$subject->contact_name, $subject->contact_position, $subject->contact_email],
            $subject instanceof Opportunity => self::personFrom(
                $contact ?? $subject->primaryContact ?? self::primaryContactOf($subject->company),
            ),
            $subject instanceof Contract => self::personFrom($contact ?? self::primaryContactOf($subject->company)),
            default => [null, null, null],
        };

        return [
            'contact.name' => $name,
            'contact.first_name' => self::firstName($name),
            'contact.position' => $position,
            'contact.email' => $email,
            'company.name' => match (true) {
                $subject instanceof Company => $subject->name,
                $subject instanceof Contact => $subject->company?->name,
                $subject instanceof Lead => $subject->company_name,
                $subject instanceof Opportunity => $subject->company?->name,
                $subject instanceof Contract => $subject->company?->name,
                default => null,
            },
            'opportunity.name' => $subject instanceof Opportunity ? $subject->name : null,
            'contract.title' => $subject instanceof Contract ? $subject->title : null,
            'contract.reference' => $subject instanceof Contract ? $subject->reference : null,
            'user.name' => $user->name,
            'user.email' => $user->email,
            'today' => Carbon::now()
                ->timezone((string) config('crm.templates.timezone', 'Asia/Manila'))
                ->format('d M Y'),
        ];
    }

    /**
     * The account's primary contact, falling back to its oldest active one — the same order
     * `Contact::syncPrimaryFor` maintains (spec 07 §3).
     */
    private static function primaryContactOf(?Company $company): ?Contact
    {
        if ($company === null) {
            return null;
        }

        return Contact::query()
            ->where('company_id', $company->id)
            ->where('status', ContactStatus::Active)
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->first();
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private static function personFrom(?Contact $contact): array
    {
        return [$contact?->name, $contact?->position, $contact?->email];
    }

    private static function firstName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return Str::of($name)->trim()->explode(' ')->first();
    }
}
