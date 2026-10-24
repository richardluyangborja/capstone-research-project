<?php

namespace App\Integrations;

/**
 * core2 / performance-management attendance seam (spec 14 §Staff performance, ADR-0020 §7).
 *
 * This is deliberately not a database query: HR data lives outside the CRM. The driver is
 * mocked for the demo (ADR-0005 pattern), and a `null` result means core2 could not answer —
 * the dashboard renders `—`, never `0`.
 */
interface HrGateway
{
    /**
     * HR figures for the given people over an inclusive date window.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, HrAttendance>|null Keyed by user id; null when core2 is unavailable.
     */
    public function attendance(array $userIds, string $from, string $to): ?array;
}
