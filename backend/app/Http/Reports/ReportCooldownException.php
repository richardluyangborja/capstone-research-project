<?php

namespace App\Http\Reports;

use RuntimeException;

/**
 * A refresh asked for inside the per-user cooldown (spec 15 §Free-tier controls).
 *
 * This is the one generate-path outcome that is an HTTP error rather than a report: the reader
 * already has a report, and the correct behaviour is to wait rather than spend free quota.
 */
final class ReportCooldownException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('The AI report was refreshed too recently.');
    }
}
