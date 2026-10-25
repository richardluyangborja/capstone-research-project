<?php

namespace App\Http\Reports;

use App\Enums\ManagementReportVariant;
use App\Http\Dashboard\DashboardPeriod;
use App\Http\Dashboard\DashboardScope;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The on-demand provider seam for management reports (module 07, spec 15, S2).
 *
 * Laravel builds the PII-free context, asks the Python service for prose, then maps the model's
 * fixed insight/action IDs to routes it already owns. The rules report remains the contract: a
 * disabled switch, missing service token or provider key, timeout, quota response or unusable JSON
 * all return a complete rules report with a fallback reason. The cache is the only persistence.
 */
final class ManagementReportGenerator
{
    /** @var array<string, mixed>|null */
    private ?array $context = null;

    public function __construct(
        private readonly User $user,
        private readonly DashboardPeriod $period,
        private readonly DashboardScope $scope,
        private readonly ManagementReportVariant $variant,
    ) {}

    /**
     * The page-load report (GET): cached model prose when it exists, otherwise the rules report.
     * This path never calls the provider.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        if (! $this->enabled()) {
            return $this->rules();
        }

        return $this->cached($this->context()) ?? $this->rules();
    }

    /**
     * Generate or refresh one report (POST). Always returns a complete report unless the caller
     * asked for a refresh inside the cooldown, which is a 429 with a retry horizon.
     *
     * @return array<string, mixed>
     */
    public function generate(bool $refresh): array
    {
        if (! $this->enabled() || ! $this->hasServiceToken()) {
            return $this->fallback('not_configured');
        }

        $context = $this->context();

        if ($refresh) {
            $seconds = $this->cooldownRemaining();

            if ($seconds > 0) {
                throw new ReportCooldownException($seconds);
            }
        } else {
            $cached = $this->cached($context);

            if ($cached !== null) {
                return $cached;
            }
        }

        if ($this->limitReached()) {
            return $this->fallback('rate_limited');
        }

        $this->recordAttempt();

        try {
            $response = $this->request($context);
        } catch (ConnectionException $exception) {
            $this->startCooldown();

            $reason = $this->isTimeout($exception) ? 'timeout' : 'service_unreachable';

            Log::warning('AI management report transport failed', [
                'variant' => $this->variant->value,
                'reason' => $reason,
                'error' => $exception::class,
            ]);

            return $this->fallback($reason);
        } catch (Throwable $exception) {
            $this->startCooldown();
            Log::warning('AI management report transport failed', [
                'variant' => $this->variant->value,
                'error' => $exception::class,
            ]);

            return $this->fallback('provider_error');
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            $this->logUpstreamFailure($response);

            return $this->fallback('service_unauthorized');
        }

        if ($status === 429) {
            $this->startCooldown();
            $this->logUpstreamFailure($response);

            return $this->fallback('rate_limited');
        }

        if ($status === 503) {
            $this->logUpstreamFailure($response);

            return $this->fallback('not_configured');
        }

        if ($status === 504) {
            $this->startCooldown();
            $this->logUpstreamFailure($response);

            return $this->fallback('timeout');
        }

        if ($status === 422) {
            $this->startCooldown();
            $this->logUpstreamFailure($response);

            return $this->fallback('invalid_response');
        }

        if (! $response->successful()) {
            $this->startCooldown();
            $this->logUpstreamFailure($response);

            return $this->fallback('provider_error');
        }

        $normalized = ManagementReportMap::normalize($this->variant, $response->json());

        if ($normalized === null) {
            $this->startCooldown();

            return $this->fallback('invalid_response');
        }

        $model = $response->json('model');
        $report = [
            'variant' => $this->variant->value,
            'title' => $this->variant->title(),
            ...$normalized,
            'generated_at' => now()->utc()->toIso8601ZuluString(),
            'source' => 'model',
            'model' => is_string($model) && trim($model) !== '' ? trim($model) : 'unknown',
            'cached' => false,
            'fallback' => null,
        ];

        Cache::put(
            $this->cacheKey($context),
            $report,
            now()->addMinutes(max(1, (int) config('ai.report.cache_ttl_minutes', 15))),
        );
        $this->startCooldown();
        $this->logGenerated($response);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return $this->context ??= (new ManagementReportContext(
            $this->user,
            $this->period,
            $this->scope,
            $this->variant,
        ))->build();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function cached(array $context): ?array
    {
        $report = Cache::get($this->cacheKey($context));

        if (! is_array($report)) {
            return null;
        }

        $report['cached'] = true;

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return (new ManagementReport($this->user, $this->period, $this->scope))
            ->build($this->variant);
    }

    /**
     * @return array<string, mixed>
     */
    private function fallback(string $reason): array
    {
        $report = $this->rules();
        $report['fallback'] = [
            'reason' => $reason,
            'message' => $this->fallbackMessage($reason),
        ];

        return $report;
    }

    private function fallbackMessage(string $reason): string
    {
        return match ($reason) {
            'not_configured' => 'AI report is not configured — showing the standard management report.',
            'service_unauthorized' => 'The AI service rejected its shared credential — check that AI_SERVICE_TOKEN matches on Laravel and the AI service.',
            'service_unreachable' => 'The AI service could not be reached — check AI_SERVICE_URL and that the AI service is running.',
            'invalid_response' => 'AI report returned an unusable response — showing the standard management report.',
            default => 'AI report is unavailable right now — showing the standard management report.',
        };
    }

    private function enabled(): bool
    {
        return (bool) config('ai.report.enabled', true);
    }

    private function serviceToken(): string
    {
        return trim((string) config('ai.report.service_token', ''));
    }

    private function hasServiceToken(): bool
    {
        return $this->serviceToken() !== '';
    }

    private function endpoint(): string
    {
        return rtrim((string) config('ai.report.service_url', 'http://127.0.0.1:8001'), '/')
            .'/reports/dashboard';
    }

    /**
     * Send one report request, retrying a connection timeout once so a sleeping public AI service
     * can wake. A provider 504 is not retried: the AI service already waited for the provider.
     *
     * @param  array<string, mixed>  $context
     */
    private function request(array $context): Response
    {
        $attempts = max(0, (int) config('ai.report.retry_attempts', 1)) + 1;
        $delayMicroseconds = max(0, (int) config('ai.report.retry_delay_ms', 750)) * 1000;
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return Http::timeout(max(0.1, (float) config('ai.report.timeout', 10)))
                    ->acceptJson()
                    ->asJson()
                    ->withToken($this->serviceToken())
                    ->post($this->endpoint(), ['context' => $context]);
            } catch (ConnectionException $exception) {
                $last = $exception;

                if ($attempt >= $attempts || ! $this->isTimeout($exception)) {
                    throw $exception;
                }

                if ($delayMicroseconds > 0) {
                    usleep($delayMicroseconds);
                }
            }
        }

        throw $last ?? new ConnectionException('The AI service could not be reached.');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function cacheKey(array $context): string
    {
        $hash = hash(
            'sha256',
            (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return sprintf(
            'ai-report:report:%d:%s:%s:%s',
            $this->user->id,
            $this->period->range->value,
            $this->variant->value,
            $hash,
        );
    }

    private function cooldownKey(): string
    {
        return sprintf('ai-report:cooldown:%d:%s', $this->user->id, $this->variant->value);
    }

    private function cooldownRemaining(): int
    {
        $until = Cache::get($this->cooldownKey());

        return is_int($until) ? max(0, $until - now()->getTimestamp()) : 0;
    }

    private function startCooldown(): void
    {
        $minutes = max(0, (int) config('ai.report.refresh_cooldown_minutes', 5));

        if ($minutes === 0) {
            return;
        }

        Cache::put(
            $this->cooldownKey(),
            now()->addMinutes($minutes)->getTimestamp(),
            $minutes * 60,
        );
    }

    private function limitReached(): bool
    {
        $userLimit = max(0, (int) config('ai.report.user_daily_limit', 30));
        $orgLimit = max(0, (int) config('ai.report.daily_limit', 800));

        if ($userLimit > 0 && (int) Cache::get($this->userCountKey(), 0) >= $userLimit) {
            return true;
        }

        return $orgLimit > 0 && (int) Cache::get($this->orgCountKey(), 0) >= $orgLimit;
    }

    private function recordAttempt(): void
    {
        $this->bump($this->userCountKey());
        $this->bump($this->orgCountKey());
    }

    private function bump(string $key): void
    {
        $ttl = now()
            ->timezone((string) config('crm.timezone', 'Asia/Manila'))
            ->endOfDay()
            ->utc();

        Cache::put($key, ((int) Cache::get($key, 0)) + 1, $ttl);
    }

    private function userCountKey(): string
    {
        return sprintf(
            'ai-report:count:user:%d:%s',
            $this->user->id,
            now()->timezone((string) config('crm.timezone', 'Asia/Manila'))->toDateString(),
        );
    }

    private function orgCountKey(): string
    {
        return 'ai-report:count:org:'
            .now()->timezone((string) config('crm.timezone', 'Asia/Manila'))->toDateString();
    }

    private function isTimeout(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out') || str_contains($message, 'timeout');
    }

    private function logUpstreamFailure(Response $response): void
    {
        $reason = $response->json('detail.reason')
            ?? $response->json('reason')
            ?? $response->json('error');

        Log::warning('AI management report upstream failed', [
            'variant' => $this->variant->value,
            'status' => $response->status(),
            'reason' => is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
        ]);
    }

    private function logGenerated(Response $response): void
    {
        $usage = $response->json('usage');
        $usage = is_array($usage) ? $usage : [];

        Log::info('AI management report generated', [
            'variant' => $this->variant->value,
            'model' => $response->json('model'),
            'status' => $response->status(),
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
        ]);
    }
}
