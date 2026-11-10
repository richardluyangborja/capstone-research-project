<?php

namespace App\Http\Audit;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Presentation and redaction rules for the audit book (module 08 slice S5, spec 19
 * §Redaction rules, ADR-0030).
 *
 * The writer (`AuditLog::record`) already strips secret-shaped keys before persistence.
 * This presenter is the second layer: it masks contact PII in snapshots and actor emails,
 * classifies the security-sensitive actions an application admin may not read, and gives
 * the CSV export one place to ask for a safe row.
 */
final class AuditPresenter
{
    /**
     * Actions an application admin may not read (spec 19 §Role capability matrix). The
     * `security.*` prefix is covered separately, so only the auth events need naming here.
     *
     * @var array<int, string>
     */
    public const SECURITY_ACTIONS = [
        'auth.login_failed',
        'auth.password_changed',
        'auth.session_revoked',
        'security.access_denied',
        'security.final_superadmin_guard',
    ];

    /**
     * Keys that must never survive into a response, even if a writer missed one.
     *
     * @var array<int, string>
     */
    private const SECRET_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'access_token', 'authorization', 'remember_token', 'secret',
        'app_key', 'jwt_secret', 'api_key', 'private_key', 'storage_path',
        'file_path', 'attachment_path',
    ];

    public static function isSecuritySensitive(string $action): bool
    {
        return str_starts_with($action, 'security.')
            || in_array($action, self::SECURITY_ACTIONS, true);
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    public static function maskSnapshot(?array $snapshot): ?array
    {
        return $snapshot === null ? null : self::maskArray($snapshot);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function maskArray(array $data): array
    {
        $masked = [];

        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);

            if (in_array($lower, self::SECRET_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $masked[$key] = self::maskArray($value);

                continue;
            }

            if (is_string($value)) {
                if (str_contains($lower, 'email') || filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                    $masked[$key] = self::maskEmail($value);

                    continue;
                }

                if (str_contains($lower, 'phone') || str_contains($lower, 'mobile') || str_contains($lower, 'contact_number')) {
                    $masked[$key] = self::maskPhone($value);

                    continue;
                }
            }

            $masked[$key] = $value;
        }

        return $masked;
    }

    public static function maskEmail(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return $email;
        }

        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return $domain === '' ? '***' : substr($local, 0, 1).'***@'.$domain;
    }

    public static function maskPhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return $phone;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? '***' : '***'.substr($digits, -4);
    }

    public static function subjectLabel(?string $subjectType): ?string
    {
        if ($subjectType === null || $subjectType === '') {
            return null;
        }

        $parts = explode('\\', $subjectType);

        return end($parts) ?: $subjectType;
    }

    /**
     * The allow-listed CSV columns (spec 19 §Redaction rules: no secrets, hashes, private
     * paths or message bodies by default).
     *
     * @return array<int, string>
     */
    public static function csvColumns(): array
    {
        return [
            'id', 'created_at', 'action', 'severity', 'actor_name', 'actor_email',
            'subject_type', 'subject_id', 'request_id', 'request_method', 'request_path',
            'request_ip', 'before', 'after',
        ];
    }

    /**
     * One CSV row, already masked and formula-injection safe.
     *
     * @return array<int, string>
     */
    public static function csvRow(AuditLog $log): array
    {
        $subjectId = null;
        if ($log->subject_type === User::class && $log->subject !== null) {
            $subjectId = $log->subject->public_id;
        }

        return array_map([self::class, 'csvCell'], [
            $log->id,
            $log->created_at?->toIso8601String(),
            $log->action,
            $log->severity->value,
            $log->actor?->name,
            self::maskEmail($log->actor?->email),
            self::subjectLabel($log->subject_type),
            $subjectId,
            $log->request_id,
            $log->request_method,
            $log->request_path,
            $log->request_ip,
            self::json($log->before),
            self::json($log->after),
        ]);
    }

    private static function json(?array $snapshot): string
    {
        $masked = self::maskSnapshot($snapshot);

        return $masked === null ? '' : (string) json_encode($masked, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Neutralise spreadsheet formula injection: a cell that starts with `=`, `+`, `-`, `@`,
     * a tab or a carriage return is prefixed with an apostrophe (spec 19 §Redaction rules).
     */
    private static function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }
}
