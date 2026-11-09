<?php

namespace App\Enums;

/**
 * Audit severities (spec 19 §Audit event vocabulary): `info`, `notice`, `warning`, `critical`.
 */
enum AuditSeverity: string
{
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Critical = 'critical';
}
