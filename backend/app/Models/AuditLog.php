<?php

namespace App\Models;

use App\Enums\AuditSeverity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
     * a ULID minted per event so a multi-step mutation can still be traced per row.
     */
    private static function requestId(Request $request): string
    {
        $header = (string) $request->headers->get('X-Request-Id');

        return $header !== '' ? $header : (string) Str::ulid();
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
