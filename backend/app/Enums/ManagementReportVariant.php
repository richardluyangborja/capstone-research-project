<?php

namespace App\Enums;

/**
 * The three rules-based management reports (module 07, spec 15, S1).
 *
 * The variant changes the question the report answers and the sentences the rules engine writes;
 * every variant reads the same scoped dashboard data and returns the same response shape.
 */
enum ManagementReportVariant: string
{
    case Executive = 'executive';
    case Pipeline = 'pipeline';
    case ClientHealth = 'client_health';

    public function label(): string
    {
        return match ($this) {
            self::Executive => 'Executive',
            self::Pipeline => 'Pipeline',
            self::ClientHealth => 'Client Health',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Executive => 'Executive management report',
            self::Pipeline => 'Pipeline management report',
            self::ClientHealth => 'Client health report',
        };
    }
}
