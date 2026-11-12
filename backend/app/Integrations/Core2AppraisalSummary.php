<?php

namespace App\Integrations;

final readonly class Core2AppraisalSummary
{
    /**
     * @param  array<string, float>  $competencies
     */
    public function __construct(
        public string $appraisalId,
        public string $periodLabel,
        public float $overallRating,
        public array $competencies,
        public string $evaluatorNotes,
        public string $conductedAt,
    ) {}
}
