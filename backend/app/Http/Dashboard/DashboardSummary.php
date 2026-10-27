<?php

namespace App\Http\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\CompanyStatus;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\SurveyResponse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The dashboard home read model (spec 14 §Data contract, D2).
 *
 * Everything is derived on read from modules 01–05 — the layer owns no table and changes no
 * module rule. The payload sends **raw values + units** (the frontend formats); money is PHP,
 * dates are Manila business dates and every list has already been narrowed by the module's own
 * `visibleTo`. The rules-based AI block ships here so the fixtures can be deleted in D2; D3
 * replaces only that block with the Python service (ADR-0020 §6).
 */
final class DashboardSummary
{
    private DashboardSignals $signals;

    public function __construct(
        private readonly User $user,
        private readonly DashboardPeriod $period,
        private readonly DashboardScope $scope,
    ) {
        $this->signals = new DashboardSignals($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $newLeads = $this->newLeads(
            $this->period->startUtc(),
            $this->period->endUtc(),
        );
        $previousNewLeads = $this->newLeads(
            $this->period->previousStartUtc(),
            $this->period->previousEndUtc(),
        );

        $qualified = $this->qualifiedLeads(
            $this->period->startUtc(),
            $this->period->endUtc(),
        );
        $previousQualified = $this->qualifiedLeads(
            $this->period->previousStartUtc(),
            $this->period->previousEndUtc(),
        );

        $pipeline = $this->openPipeline();
        $previousPipeline = $this->openPipelineAtStart();

        $won = $this->revenueWon($this->period->startDate(), $this->period->endDate());
        $previousWon = $this->revenueWon(
            $this->period->previousStartDate(),
            $this->period->previousEndDate(),
        );

        $closed = $this->closedCounts($this->period->startDate(), $this->period->endDate());
        $previousClosed = $this->closedCounts(
            $this->period->previousStartDate(),
            $this->period->previousEndDate(),
        );

        $winRate = $this->winRate($closed);
        $previousWinRate = $this->winRate($previousClosed);

        $clients = $this->activeClients();
        $clientsAdded = $this->clientsAdded();
        $followUpsDue = $this->followUpsDue();
        $attention = $this->signals->attention();
        $previousAttention = $this->previousAttention();
        $overdueFollowUps = $this->signals->overdueFollowUps();

        $primary = [
            $this->kpi(
                'new-leads',
                'New leads',
                $newLeads,
                'count',
                'counts',
                $this->percentTrend($newLeads, $previousNewLeads),
                'Captured in the period',
                '/leads',
            ),
            $this->kpi(
                'qualified',
                'Qualified leads',
                $qualified,
                'count',
                'counts',
                $this->percentTrend($qualified, $previousQualified),
                'Passed the minimum criteria',
                '/leads?view=qualification',
            ),
            $this->kpi(
                'pipeline',
                'Open pipeline',
                $pipeline,
                'currency',
                'cost',
                $this->percentTrend($pipeline, $previousPipeline),
                'Value of open deals',
                '/pipeline',
            ),
            $this->kpi(
                'won',
                'Revenue won',
                $won,
                'currency',
                'cost',
                $this->percentTrend($won, $previousWon),
                'Closed-won in the period',
                '/opportunities',
            ),
        ];

        $secondary = [
            $this->kpi(
                'win-rate',
                'Win rate',
                $winRate,
                'percent',
                'counts',
                $this->pointsTrend($winRate, $previousWinRate),
                'Won ÷ closed deals',
                '/opportunities',
            ),
            $this->kpi(
                'clients',
                'Active clients',
                $clients,
                'count',
                'people',
                $this->countTrend($clientsAdded),
                'Past prospect, not churned',
                '/accounts?view=clients',
            ),
            $this->kpi(
                'follow-ups',
                'Follow-ups due',
                $followUpsDue,
                'count',
                'activity',
                [
                    'direction' => $overdueFollowUps > 0 ? 'up' : 'flat',
                    'value' => $overdueFollowUps,
                    'unit' => 'count',
                    'tone' => $overdueFollowUps > 0 ? 'negative' : 'neutral',
                ],
                'Open tasks + scheduled sends',
                '/follow-ups',
            ),
            $this->kpi(
                'attention',
                'Needs attention',
                $attention,
                'count',
                'people',
                $this->attentionTrend($attention, $previousAttention),
                'Unhappy answers and at-risk accounts',
                '/satisfaction/responses?attention=true',
            ),
        ];

        $donut = $this->donut();

        return [
            'range' => $this->period->range->value,
            'updated_at' => now()->utc()->toIso8601ZuluString(),
            'scope' => $this->scope->toArray($this->user),
            'primary' => $primary,
            'secondary' => $secondary,
            'line' => $this->line(),
            'donut' => $donut,
            'alerts' => $this->alerts(),
            'activity' => $this->activity(),
            'ai' => $this->ai([...$primary, ...$secondary], $donut, $closed),
        ];
    }

    /**
     * @param  array{direction: string, value: int|float, unit: string, tone: string}|null  $trend
     * @return array<string, mixed>
     */
    private function kpi(
        string $id,
        string $label,
        int|float $value,
        string $unit,
        string $domain,
        ?array $trend,
        string $hint,
        string $href,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'value' => $value,
            'unit' => $unit,
            'domain' => $domain,
            'trend' => $trend,
            'hint' => $hint,
            'href' => $href,
        ];
    }

    private function newLeads(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Lead::query()
            ->visibleTo($this->user)
            ->where('status', '!=', LeadStatus::Archived)
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    private function qualifiedLeads(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Lead::query()
            ->visibleTo($this->user)
            ->where('status', LeadStatus::Qualified)
            ->whereBetween('status_changed_at', [$start, $end])
            ->count();
    }

    private function openPipeline(): float
    {
        return round((float) Opportunity::query()
            ->visibleTo($this->user)
            ->open()
            ->sum('amount'), 2);
    }

    /**
     * The open pipeline as it stood when this period opened: deals written before the window
     * that had not closed before it. Stage history is not stored, so this is the snapshot the
     * deal rows themselves can reconstruct.
     */
    private function openPipelineAtStart(): float
    {
        return round((float) Opportunity::query()
            ->visibleTo($this->user)
            ->where('created_at', '<', $this->period->startUtc())
            ->where(function (Builder $query): void {
                $query->whereNull('actual_close_date')
                    ->orWhere('actual_close_date', '>=', $this->period->startDate());
            })
            ->sum('amount'), 2);
    }

    private function revenueWon(string $startDate, string $endDate): float
    {
        return round((float) Opportunity::query()
            ->visibleTo($this->user)
            ->where('stage', OpportunityStage::Won)
            ->whereBetween('actual_close_date', [$startDate, $endDate])
            ->sum('amount'), 2);
    }

    /**
     * @return array{won: int, lost: int}
     */
    private function closedCounts(string $startDate, string $endDate): array
    {
        $base = Opportunity::query()
            ->visibleTo($this->user)
            ->whereIn('stage', [OpportunityStage::Won, OpportunityStage::Lost])
            ->whereBetween('actual_close_date', [$startDate, $endDate]);

        return [
            'won' => (clone $base)->where('stage', OpportunityStage::Won)->count(),
            'lost' => (clone $base)->where('stage', OpportunityStage::Lost)->count(),
        ];
    }

    /**
     * @param  array{won: int, lost: int}  $closed
     */
    private function winRate(array $closed): float
    {
        $total = $closed['won'] + $closed['lost'];

        return $total === 0 ? 0.0 : round(($closed['won'] / $total) * 100, 1);
    }

    private function activeClients(): int
    {
        return Company::query()
            ->visibleTo($this->user)
            ->whereNotIn('status', [CompanyStatus::Prospect, CompanyStatus::Churned])
            ->count();
    }

    private function clientsAdded(): int
    {
        return Company::query()
            ->visibleTo($this->user)
            ->whereNotIn('status', [CompanyStatus::Prospect, CompanyStatus::Churned])
            ->whereBetween('client_since', [$this->period->startDate(), $this->period->endDate()])
            ->count();
    }

    private function followUpsDue(): int
    {
        // The KPI is the work owed at the period's end — the same open tasks + scheduled
        // sends the module 04 worklist lists — so the number reconciles with `/follow-ups`
        // instead of hiding tomorrow's work behind a lookback window.
        $tasks = Activity::query()
            ->visibleTo($this->user)
            ->tasks()
            ->where('status', ActivityStatus::Open)
            ->count();

        $messages = Communication::query()
            ->visibleTo($this->user)
            ->scheduled()
            ->count();

        return $tasks + $messages;
    }

    /**
     * The attention count at the previous window's end, reconstructed only for the survey half
     * (a resolution and a new answer are both timestamped); at-risk accounts are a snapshot
     * with no history, so they are held constant.
     */
    private function previousAttention(): int
    {
        $flagged = SurveyResponse::query()
            ->visibleTo($this->user)
            ->where('needs_attention', true)
            ->whereBetween('submitted_at', [$this->period->startUtc(), $this->period->endUtc()])
            ->count();

        $resolved = SurveyResponse::query()
            ->visibleTo($this->user)
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$this->period->startUtc(), $this->period->endUtc()])
            ->count();

        $previousSurveys = max(0, $this->signals->unresolvedSurveys() - $flagged + $resolved);

        return $previousSurveys + $this->signals->atRiskCompanies();
    }

    /**
     * @return array{direction: string, value: int, unit: string, tone: string}
     */
    private function percentTrend(int|float $current, int|float $previous, bool $positiveWhenUp = true): array
    {
        if ((float) $previous <= 0.0) {
            if ((float) $current <= 0.0) {
                return $this->flatTrend();
            }

            return [
                'direction' => 'up',
                'value' => 100,
                'unit' => 'percent',
                'tone' => $positiveWhenUp ? 'positive' : 'negative',
            ];
        }

        $delta = (((float) $current - (float) $previous) / (float) $previous) * 100;
        $value = (int) round(abs($delta));

        if ($value === 0) {
            return $this->flatTrend();
        }

        return [
            'direction' => $delta > 0 ? 'up' : 'down',
            'value' => $value,
            'unit' => 'percent',
            'tone' => ($delta > 0) === $positiveWhenUp ? 'positive' : 'negative',
        ];
    }

    /**
     * @return array{direction: string, value: float, unit: string, tone: string}
     */
    private function pointsTrend(float $current, float $previous, bool $positiveWhenUp = true): array
    {
        $delta = round($current - $previous, 1);

        if ($delta === 0.0) {
            return $this->flatTrend();
        }

        return [
            'direction' => $delta > 0 ? 'up' : 'down',
            'value' => abs($delta),
            'unit' => 'points',
            'tone' => ($delta > 0) === $positiveWhenUp ? 'positive' : 'negative',
        ];
    }

    /**
     * @return array{direction: string, value: int, unit: string, tone: string}
     */
    private function countTrend(int $value): array
    {
        if ($value === 0) {
            return $this->flatTrend();
        }

        return [
            'direction' => 'up',
            'value' => $value,
            'unit' => 'count',
            'tone' => 'positive',
        ];
    }

    /**
     * Attention going down is good, so the tone is inverted.
     *
     * @return array{direction: string, value: int, unit: string, tone: string}
     */
    private function attentionTrend(int $current, int $previous): array
    {
        $delta = $current - $previous;

        if ($delta === 0) {
            return $this->flatTrend();
        }

        return [
            'direction' => $delta > 0 ? 'up' : 'down',
            'value' => abs($delta),
            'unit' => 'count',
            'tone' => $delta > 0 ? 'negative' : 'positive',
        ];
    }

    /**
     * @return array{direction: string, value: int, unit: string, tone: string}
     */
    private function flatTrend(): array
    {
        return [
            'direction' => 'flat',
            'value' => 0,
            'unit' => 'count',
            'tone' => 'neutral',
        ];
    }

    /**
     * @return array{caption: string, labels: array<int, string>, series: array<int, array{name: string, domain: string, values: array<int, int>}>}
     */
    private function line(): array
    {
        $buckets = $this->period->buckets();

        $leadDates = Lead::query()
            ->visibleTo($this->user)
            ->where('status', '!=', LeadStatus::Archived)
            ->whereBetween('created_at', [$this->period->startUtc(), $this->period->endUtc()])
            ->pluck('created_at');

        $qualifiedDates = Lead::query()
            ->visibleTo($this->user)
            ->where('status', LeadStatus::Qualified)
            ->whereBetween('status_changed_at', [$this->period->startUtc(), $this->period->endUtc()])
            ->pluck('status_changed_at');

        $wonDates = Opportunity::query()
            ->visibleTo($this->user)
            ->where('stage', OpportunityStage::Won)
            ->whereBetween('actual_close_date', [$this->period->startDate(), $this->period->endDate()])
            ->pluck('actual_close_date');

        return [
            'caption' => $this->period->caption(),
            'labels' => array_column($buckets, 'label'),
            'series' => [
                [
                    'name' => 'New leads',
                    'domain' => 'counts',
                    'values' => $this->countByBucket($buckets, $leadDates),
                ],
                [
                    'name' => 'Qualified',
                    'domain' => 'people',
                    'values' => $this->countByBucket($buckets, $qualifiedDates),
                ],
                [
                    'name' => 'Won',
                    'domain' => 'cost',
                    'values' => $this->countByBucket($buckets, $wonDates),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>  $buckets
     * @param  Collection<int, mixed>  $dates
     * @return array<int, int>
     */
    private function countByBucket(array $buckets, Collection $dates): array
    {
        $counts = array_fill(0, count($buckets), 0);
        $timezone = (string) config('crm.timezone', 'Asia/Manila');

        foreach ($dates as $date) {
            if ($date === null) {
                continue;
            }

            $at = $date instanceof CarbonInterface
                ? CarbonImmutable::instance($date)
                : CarbonImmutable::parse((string) $date);

            $at = $at->setTimezone($timezone);

            foreach ($buckets as $index => $bucket) {
                if ($at->betweenIncluded($bucket['start'], $bucket['end'])) {
                    $counts[$index]++;

                    break;
                }
            }
        }

        return $counts;
    }

    /**
     * @return array{caption: string, total: float, segments: array<int, array{label: string, value: float, domain: string, href: string}>}
     */
    private function donut(): array
    {
        $sums = Opportunity::query()
            ->visibleTo($this->user)
            ->open()
            ->selectRaw('stage, COALESCE(SUM(amount), 0) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

        $segments = [];

        foreach (OpportunityStage::openStages() as $stage) {
            $value = round((float) ($sums[$stage->value] ?? 0), 2);

            if ($value <= 0) {
                continue;
            }

            $segments[] = [
                'label' => $this->stageLabel($stage),
                'value' => $value,
                'domain' => $this->stageDomain($stage),
                'href' => '/pipeline',
            ];
        }

        return [
            'caption' => 'Open pipeline by stage',
            'total' => round(array_sum(array_column($segments, 'value')), 2),
            'segments' => $segments,
        ];
    }

    private function stageLabel(OpportunityStage $stage): string
    {
        return match ($stage) {
            OpportunityStage::NewInquiry => 'New inquiry',
            OpportunityStage::Qualified => 'Qualified',
            OpportunityStage::Discovery => 'Discovery',
            OpportunityStage::ProposalSent => 'Proposal sent',
            OpportunityStage::Negotiation => 'Negotiation',
            OpportunityStage::OnHold => 'On hold',
            OpportunityStage::Won => 'Won',
            OpportunityStage::Lost => 'Lost',
        };
    }

    private function stageDomain(OpportunityStage $stage): string
    {
        return match ($stage) {
            OpportunityStage::NewInquiry => 'counts',
            OpportunityStage::Qualified => 'people',
            OpportunityStage::Negotiation => 'cost',
            default => 'activity',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function alerts(): array
    {
        $attention = $this->signals->attention();
        $surveys = $this->signals->unresolvedSurveys();
        $atRisk = $this->signals->atRiskCompanies();
        $overdue = $this->signals->overdueFollowUps();
        $contracts = $this->signals->expiringContracts();
        $unassigned = $this->signals->unassignedLeads();

        $alerts = [];

        if ($attention > 0) {
            $alerts[] = [
                'id' => 'alert-attention',
                'variant' => 'danger',
                'title' => $attention.' '.$this->plural($attention, 'account').' '.$this->verb($attention, 'needs', 'need').' attention',
                'body' => $this->attentionBody($surveys, $atRisk),
                'href' => '/satisfaction/responses?attention=true',
                'link_label' => 'Read the answers',
                'minTier' => 'personal',
            ];
        }

        if ($overdue > 0) {
            $alerts[] = [
                'id' => 'alert-follow-ups',
                'variant' => 'warning',
                'title' => $overdue.' '.$this->plural($overdue, 'follow-up').' past due',
                'body' => 'Open tasks and scheduled sends whose moment has passed.',
                'href' => '/follow-ups',
                'link_label' => 'Open the worklist',
                'minTier' => 'personal',
            ];
        }

        if ($contracts > 0) {
            $days = (int) config('crm.contracts.expiring_soon_days', 30);
            $alerts[] = [
                'id' => 'alert-contracts',
                'variant' => 'info',
                'title' => $contracts.' '.$this->plural($contracts, 'contract').' expiring within '.$days.' days',
                'body' => 'Signed contracts inside the renewal window.',
                'href' => '/accounts?view=clients',
                'link_label' => 'Open clients',
                'minTier' => 'team',
            ];
        }

        if ($unassigned > 0) {
            $alerts[] = [
                'id' => 'alert-unassigned',
                'variant' => 'info',
                'title' => $unassigned.' '.$this->plural($unassigned, 'unassigned lead').' waiting',
                'body' => 'Nobody has touched the shared queue for a day.',
                'href' => '/leads?view=unassigned',
                'link_label' => 'Claim the lead',
                'minTier' => 'team',
            ];
        }

        return array_values(array_filter(
            $alerts,
            fn (array $alert): bool => $this->scope->allows((string) $alert['minTier']),
        ));
    }

    private function plural(int $count, string $singular): string
    {
        if ($count === 1) {
            return $singular;
        }

        return str_ends_with($singular, 's') ? $singular : $singular.'s';
    }

    private function verb(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? $singular : $plural;
    }

    /**
     * The attention breakdown, without naming an empty half ("1 answer needs a callback").
     */
    private function attentionBody(int $surveys, int $atRisk): string
    {
        if ($surveys > 0 && $atRisk > 0) {
            return $surveys.' '.$this->plural($surveys, 'unhappy answer').' and '.$atRisk.' '.$this->plural($atRisk, 'at-risk account').' need a human.';
        }

        if ($surveys > 0) {
            return $surveys.' '.$this->plural($surveys, 'unhappy answer').' '.$this->verb($surveys, 'needs', 'need').' a callback.';
        }

        if ($atRisk > 0) {
            return $atRisk.' '.$this->plural($atRisk, 'account').' '.$this->verb($atRisk, 'is', 'are').' marked at risk.';
        }

        return 'No unhappy answers or at-risk accounts are waiting.';
    }

    /**
     * The last six things that happened across the modules the caller may see.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activity(): array
    {
        $rows = Activity::query()
            ->visibleTo($this->user)
            ->with('subjectable')
            ->where(function (Builder $query): void {
                $query->where('type', '!=', ActivityType::Task)
                    ->orWhere(function (Builder $query): void {
                        $query->where('type', ActivityType::Task)
                            ->where('status', ActivityStatus::Done);
                    });
            })
            ->orderByRaw('COALESCE(occurred_at, updated_at) DESC')
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        return $rows
            ->map(fn (Activity $activity): array => [
                'id' => $activity->id,
                'domain' => $this->activityDomain($activity),
                'title' => $this->activityTitle($activity),
                'detail' => $this->activityDetail($activity),
                'occurred_at' => ($activity->occurred_at ?? $activity->updated_at)->utc()->toIso8601ZuluString(),
                'href' => $this->activityHref($activity->subjectable),
            ])
            ->values()
            ->all();
    }

    private function activityDomain(Activity $activity): string
    {
        $subject = (string) $activity->subject;

        return match ($activity->type) {
            ActivityType::Email => 'counts',
            ActivityType::Task => 'activity',
            ActivityType::System => match (true) {
                str_contains($subject, 'Stage changed'),
                str_contains($subject, 'signed'),
                str_contains($subject, 'handoff') => 'cost',
                str_contains($subject, 'Status changed') => 'people',
                default => 'activity',
            },
            default => 'activity',
        };
    }

    private function activityTitle(Activity $activity): string
    {
        return match ($activity->type) {
            ActivityType::Email => 'Email logged',
            ActivityType::Call => 'Call logged',
            ActivityType::Meeting => 'Meeting logged',
            ActivityType::WalkIn => 'Walk-in logged',
            ActivityType::Note => 'Note added',
            ActivityType::Task => $activity->status === ActivityStatus::Done
                ? 'Follow-up completed'
                : 'Follow-up',
            ActivityType::System => $this->systemTitle((string) $activity->subject),
        };
    }

    private function systemTitle(string $subject): string
    {
        return match (true) {
            str_contains($subject, 'Survey response received') => 'Survey answered',
            str_contains($subject, 'Survey response resolved') => 'Survey answer resolved',
            str_contains($subject, 'Status changed') && str_contains($subject, 'qualified') => 'Lead qualified',
            str_contains($subject, 'Status changed') => 'Lead status changed',
            str_contains($subject, 'Stage changed') => 'Deal moved',
            str_contains($subject, 'signed') => 'Contract signed',
            str_contains($subject, 'handoff failed') => 'Service handoff failed',
            default => 'System note',
        };
    }

    private function activityDetail(Activity $activity): string
    {
        $detail = trim((string) ($activity->subject ?? ''));
        $label = $this->recordLabel($activity->subjectable);

        if ($label !== null && $detail !== '' && ! str_contains($detail, $label)) {
            $detail .= ' · '.$label;
        }

        return $detail !== '' ? $detail : ($label ?? 'Activity');
    }

    private function recordLabel(mixed $subject): ?string
    {
        return match (true) {
            $subject instanceof Lead => $subject->company_name,
            $subject instanceof Company => $subject->name,
            $subject instanceof Contact => $subject->name,
            $subject instanceof Opportunity => $subject->name,
            $subject instanceof Contract => $subject->title,
            default => null,
        };
    }

    private function activityHref(mixed $subject): string
    {
        return match (true) {
            $subject instanceof Lead => '/leads/'.$subject->id,
            $subject instanceof Company => '/accounts/'.$subject->id,
            $subject instanceof Contact => '/contacts',
            $subject instanceof Opportunity => '/opportunities/'.$subject->id,
            $subject instanceof Contract => $subject->company_id !== null
                ? '/accounts/'.$subject->company_id.'?tab=contracts'
                : '/accounts?view=clients',
            default => '/dashboard',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $kpis
     * @param  array{caption: string, total: float, segments: array<int, array{label: string, value: float, domain: string, href: string}>}  $donut
     * @param  array{won: int, lost: int}  $closed
     * @return array<string, mixed>
     */
    private function ai(array $kpis, array $donut, array $closed): array
    {
        /** @var Collection<string, array<string, mixed>> $byId */
        $byId = collect($kpis)->keyBy('id');

        $newLeads = (int) ($byId->get('new-leads')['value'] ?? 0);
        $pipeline = (float) ($byId->get('pipeline')['value'] ?? 0);
        $won = (float) ($byId->get('won')['value'] ?? 0);
        $winRate = (float) ($byId->get('win-rate')['value'] ?? 0);
        $clients = (int) ($byId->get('clients')['value'] ?? 0);
        $followUps = (int) ($byId->get('follow-ups')['value'] ?? 0);
        $attention = (int) ($byId->get('attention')['value'] ?? 0);

        $surveys = $this->signals->unresolvedSurveys();
        $atRisk = $this->signals->atRiskCompanies();
        $overdue = $this->signals->overdueFollowUps();

        /** @var array{label: string, value: float}|null $top */
        $top = collect($donut['segments'])->sortByDesc('value')->first();

        $headline = $attention > 0
            ? $attention.' '.$this->plural($attention, 'account').' '.$this->verb($attention, 'needs', 'need').' a human this week.'
            : ($won > 0
                ? 'The book is healthy — '.$this->peso($won).' closed-won this period.'
                : 'The book is quiet — no revenue closed this period.');

        $book = match ($this->scope->tier) {
            'team' => "team's book",
            'org' => 'org-wide book',
            default => 'personal book',
        };

        $closedTotal = $closed['won'] + $closed['lost'];
        $opening = $closedTotal === 0
            ? 'The '.$book.' has no closed deals, with '
            : 'The '.$book.' is at a '.$this->number($winRate).'% win rate, with ';

        $narrative = $opening
            .$this->peso($pipeline).' open and '.$this->peso($won).' closed-won this period. '
            .$clients.' active '.$this->plural($clients, 'client').' '.$this->verb($clients, 'is', 'are').' healthy, with '
            .$followUps.' '.$this->plural($followUps, 'follow-up').' on the worklist and '.$overdue.' already past due. '
            .$this->attentionBody($surveys, $atRisk);

        $insights = [
            [
                'id' => 'ai-attention',
                'title' => $attention > 0 ? 'The unhappy queue' : 'No unhappy answers',
                'body' => $this->attentionBody($surveys, $atRisk),
                'domain' => 'people',
                'href' => '/satisfaction/responses?attention=true',
                'link_label' => 'Read the answers',
            ],
            [
                'id' => 'ai-pipeline',
                'title' => $top !== null ? $top['label'].' is where the value sits' : 'The pipeline is empty',
                'body' => $top !== null
                    ? $top['label'].' holds '.$this->peso((float) $top['value']).' of the '.$this->peso($donut['total']).' open pipeline.'
                    : 'No open deals are on the board.',
                'domain' => 'cost',
                'href' => '/pipeline',
                'link_label' => 'Open the board',
            ],
            [
                'id' => 'ai-follow-ups',
                'title' => 'The work still owed',
                'body' => $followUps.' '.$this->plural($followUps, 'follow-up').' '.$this->verb($followUps, 'is', 'are').' on the worklist; '
                    .($overdue > 0 ? $overdue.' already past due.' : 'none are past due.'),
                'domain' => 'activity',
                'href' => '/follow-ups',
                'link_label' => 'Review follow-ups',
            ],
        ];

        $actions = [
            [
                'id' => 'ai-action-call',
                'label' => $attention > 0 ? 'Call back unhappy clients' : 'Read the latest answers',
                'description' => $attention > 0
                    ? 'Acknowledge the low scores and close the loop with a note.'
                    : 'See what clients said about the work.',
                'href' => '/satisfaction/responses?attention=true',
            ],
            [
                'id' => 'ai-action-board',
                'label' => 'Unstick the pipeline',
                'description' => 'Review the open deals that have not moved.',
                'href' => '/pipeline',
            ],
            [
                'id' => 'ai-action-follow-ups',
                'label' => 'Clear the overdue queue',
                'description' => 'Reschedule or complete what is past due.',
                'href' => '/follow-ups',
            ],
            [
                'id' => 'ai-action-people',
                'label' => 'Review the staff scorecard',
                'description' => 'Compare CRM output with core2 attendance.',
                'href' => '/leads?view=team',
            ],
        ];

        return [
            'headline' => $headline,
            'narrative' => $narrative,
            'insights' => $insights,
            'actions' => $actions,
            'model' => 'Primepower rules v1',
            'generated_at' => now()->utc()->toIso8601ZuluString(),
            'source' => 'rules',
        ];
    }

    private function peso(float $amount): string
    {
        if ($amount >= 1_000_000) {
            return '₱'.number_format($amount / 1_000_000, 2).'M';
        }

        if ($amount >= 1_000) {
            return '₱'.number_format($amount / 1_000, 0).'K';
        }

        return '₱'.number_format($amount, 0);
    }

    private function number(float $value): string
    {
        return fmod($value, 1.0) === 0.0
            ? (string) (int) $value
            : number_format($value, 1);
    }
}
