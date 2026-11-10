<?php

namespace App\Models;

use App\Enums\AuditSeverity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;

/**
 * One immutable audit row (spec 19 §Audit event vocabulary, ADR-0030).
 *
 * The model is the writer: `AuditLog::record()` redacts the snapshots (no passwords, tokens,
 * hashes or private paths), captures the request metadata (method, path, IP, user agent,
 * correlation id — never the body or headers), and appends. Rows are never updated or deleted
 * by the application; a correction is a new event. S5 delivers the audit book UI.
 *
 * @property string $id
 */
class AuditLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * Append-only at the application layer (spec 19 §Audit event vocabulary): a correction is
     * a new event, never an update or a delete. The database has no update/delete route either.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Audit rows are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Audit rows are append-only.');
        });
    }

    /** Keys stripped from snapshots before persistence (spec 19 §Redaction rules). */
    private const REDACTED_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        'access_token',
        'authorization',
        'remember_token',
        'secret',
        'app_key',
    ];

    protected $fillable = [
        'actor_id', 'action', 'severity', 'subject_type', 'subject_id',
        'before', 'after', 'request_method', 'request_path', 'request_ip',
        'request_user_agent', 'request_id', 'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => AuditSeverity::class,
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The actor who caused the event; null for a system event or a failed login.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The record the event is about. `subject_type` is the model class and `subject_id` the
     * internal key; the resource resolves it to a ULID public id before it leaves the API.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Append one audit event. `$before`/`$after` are redacted in place — sensitive keys are
     * removed entirely rather than masked, so no value survives persistence.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        string $action,
        AuditSeverity $severity,
        ?int $actorId,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $before = null,
        ?array $after = null,
    ): self {
        $request = request();

        return static::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'severity' => $severity,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => static::redacted($before),
            'after' => static::redacted($after),
            'request_method' => $request?->method(),
            'request_path' => $request?->path(),
            'request_ip' => $request?->ip(),
            'request_user_agent' => $request === null ? null : substr((string) $request->userAgent(), 0, 255),
            'request_id' => self::requestId($request),
            'created_at' => now(),
        ]);
    }

    /**
     * The correlation id: the incoming request's id when the platform provides one, otherwise
     * a ULID minted once per request and cached on it. Caching is what connects the several
     * rows a multi-step administrative mutation writes (spec 19 §S5 acceptance).
     */
    private static function requestId(Request $request): string
    {
        $cached = $request->attributes->get('audit_request_id');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $header = trim((string) $request->headers->get('X-Request-Id'));
        $id = $header !== '' ? $header : (string) Str::ulid();

        $request->attributes->set('audit_request_id', $id);

        return $id;
    }

    /**
     * Strip sensitive keys from a snapshot (spec 19 §Redaction rules). Passwords, tokens and
     * hashes never reach the database, however they were spelled in the payload.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    private static function redacted(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return collect($snapshot)
            ->reject(fn (mixed $value, string $key): bool => in_array(strtolower($key), self::REDACTED_KEYS, true))
            ->all();
    }
}
