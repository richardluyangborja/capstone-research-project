<?php

namespace App\Http\Reports;

use App\Enums\CompanyStatus;
use App\Enums\ManagementReportVariant;
use App\Enums\OpportunityStage;
use App\Http\Dashboard\DashboardPeriod;
use App\Http\Dashboard\DashboardScope;
use App\Http\Dashboard\DashboardSignals;
use App\Http\Dashboard\DashboardSummary;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The rules-based management report (module 07, spec 15, S1).
 *
 * The report is a read over the same scoped data as the dashboard: it owns no table, changes no
 * module rule and never calls a provider. Each variant answers a different management question
 * from the same numbers, and every link points at the page that owns the work.
 */
final class ManagementReport
{
    private Collection $kpis;

    /** @var array{won: int, lost: int} */
    private array $closed;

    private DashboardSignals $signals;

    /**
     * @var array<string, mixed>
     */
    private array $summary;

    public function __construct(
        private readonly User $user,
        private readonly DashboardPeriod $period,
        private readonly DashboardScope $scope,
    ) {
        $this->summary = (new DashboardSummary($user, $period, $scope))->build();
        $this->kpis = collect([...$this->summary['primary'], ...$this->summary['secondary']])
            ->keyBy('id');
        $this->closed = $this->closedCounts();
        $this->signals = new DashboardSignals($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(ManagementReportVariant $variant): array
    {
        $body = match ($variant) {
            ManagementReportVariant::Executive => $this->executive(),
            ManagementReportVariant::Pipeline => $this->pipeline(),
            ManagementReportVariant::ClientHealth => $this->clientHealth(),
        };

        return [
            'variant' => $variant->value,
            'title' => $variant->title(),
            ...$body,
            'generated_at' => now()->utc()->toIso8601ZuluString(),
            'source' => 'rules',
            'model' => 'Primepower rules v1',
            'cached' => false,
            'fallback' => null,
        ];
    }

    /**
     * “What is the state of the book this period?”
     *
     * @return array{headline: string, narrative: string, insights: array<int, array<string, mixed>>, actions: array<int, array<string, mixed>>}
     */
    private function executive(): array
    {
        $newLeads = (int) $this->value('new-leads');
        $qualified = (int) $this->value('qualified');
        $pipeline = (float) $this->value('pipeline');
        $won = (float) $this->value('won');
        $winRate = (float) $this->value('win-rate');
        $clients = (int) $this->value('clients');
        $followUps = (int) $this->value('follow-ups');
        $attention = (int) $this->value('attention');

        $surveys = $this->signals->unresolvedSurveys();
        $atRisk = $this->signals->atRiskCompanies();
        $overdue = $this->signals->overdueFollowUps();
        $top = $this->topStage();
        $closedTotal = $this->closed['won'] + $this->closed['lost'];

        $book = match ($this->scope->tier) {
            'team' => "team's book",
            'org' => 'org-wide book',
            default => 'personal book',
        };

        $headline = $attention > 0
            ? $attention.' '.$this->plural($attention, 'account').' '.$this->verb($attention, 'needs', 'need').' a human this week.'
            : ($won > 0
                ? 'The book is healthy — '.$this->peso($won).' closed-won this period.'
                : 'The book is quiet — no revenue closed this period.');

        $opening = $closedTotal === 0
            ? 'The '.$book.' has no closed deals this period; it carries '
            : 'The '.$book.' is at a '.$this->number($winRate).'% win rate, with ';

        $narrative = $opening
            .$this->peso($pipeline).' open and '.$this->peso($won).' closed-won this period. '
            .$newLeads.' new '.$this->plural($newLeads, 'lead').' were captured and '
            .$qualified.' qualified. '
            .$clients.' active '.$this->plural($clients, 'client').' '
            .$this->verb($clients, 'is', 'are').' on the book, with '
            .$followUps.' '.$this->plural($followUps, 'follow-up').' owed and '
            .$overdue.' already past due. '
            .$this->attentionBody($surveys, $atRisk);

        return [
            'headline' => $headline,
            'narrative' => $narrative,
            'insights' => [
                [
                    'id' => 'attention',
                    'title' => $attention > 0 ? 'The unhappy queue' : 'No unhappy answers',
                    'body' => $this->attentionBody($surveys, $atRisk),
                    'domain' => 'people',
                    'href' => '/satisfaction/responses?attention=true',
                    'link_label' => 'Read the answers',
                ],
                [
                    'id' => 'pipeline',
                    'title' => $top !== null ? $top['label'].' is where the value sits' : 'The pipeline is empty',
                    'body' => $top !== null
                        ? $top['label'].' holds '.$this->peso((float) $top['value']).' of the '.$this->peso((float) $this->summary['donut']['total']).' open pipeline.'
                        : 'No open deals are on the board.',
                    'domain' => 'cost',
                    'href' => '/pipeline',
                    'link_label' => 'Open the board',
                ],
                [
                    'id' => 'follow_ups',
                    'title' => 'The work still owed',
                    'body' => $followUps.' '.$this->plural($followUps, 'follow-up').' '
                        .$this->verb($followUps, 'is', 'are').' on the worklist; '
                        .($overdue > 0 ? $overdue.' already past due.' : 'none are past due.'),
                    'domain' => 'activity',
                    'href' => '/follow-ups',
                    'link_label' => 'Review follow-ups',
                ],
            ],
            'actions' => [
                [
                    'id' => 'read_answers',
                    'label' => $attention > 0 ? 'Call back unhappy clients' : 'Read the latest answers',
                    'description' => $attention > 0
                        ? 'Acknowledge the low scores and close the loop with a note.'
                        : 'See what clients said about the work.',
                    'href' => '/satisfaction/responses?attention=true',
                ],
                [
                    'id' => 'open_board',
                    'label' => 'Unstick the pipeline',
                    'description' => 'Review the open deals that have not moved.',
                    'href' => '/pipeline',
                ],
                [
                    'id' => 'open_worklist',
                    'label' => 'Clear the overdue queue',
                    'description' => 'Reschedule or complete what is past due.',
                    'href' => '/follow-ups',
                ],
                [
                    'id' => 'open_clients',
                    'label' => 'Review the client book',
                    'description' => 'Check lifecycle, renewals and account health.',
                    'href' => '/clients',
                ],
            ],
        ];
    }

    /**
     * “Where is the value, and what is stuck?”
     *
     * @return array{headline: string, narrative: string, insights: array<int, array<string, mixed>>, actions: array<int, array<string, mixed>>}
     */
    private function pipeline(): array
    {
        $pipeline = (float) $this->value('pipeline');
        $winRate = (float) $this->value('win-rate');
        $openDeals = Opportunity::query()
            ->visibleTo($this->user)
            ->open()
            ->count();
        $closedTotal = $this->closed['won'] + $this->closed['lost'];
        $stalled = $this->signals->stalledDeals();
        $stuckDays = (int) config('crm.pipeline.stuck_days', 21);
        $top = $this->topStage();
        $share = $top !== null && $pipeline > 0
            ? (int) round(((float) $top['value'] / $pipeline) * 100)
            : 0;

        $headline = $top !== null
            ? $top['label'].' holds '.$this->peso((float) $top['value']).' of the open pipeline.'
            : 'The pipeline is clear — no open deals this period.';

        $narrative = $openDeals.' open '.$this->plural($openDeals, 'deal').' worth '
            .$this->peso($pipeline).'. '
            .($top !== null
                ? $top['label'].' holds '.$this->peso((float) $top['value']).' ('.$share.'% of the open value). '
                : 'No stage carries value yet. ')
            .($closedTotal === 0
                ? 'No deals have closed this period.'
                : $this->closed['won'].' won and '.$this->closed['lost'].' lost, a '.$this->number($winRate).'% win rate.')
            .' '.$stalled.' open '.$this->plural($stalled, 'deal').' '
            .$this->verb($stalled, 'has', 'have').' not moved in '.$stuckDays.' days.';

        return [
            'headline' => $headline,
            'narrative' => $narrative,
            'insights' => [
                [
                    'id' => 'stages',
                    'title' => $top !== null ? 'Where the value sits' : 'No value on the board',
                    'body' => $top !== null
                        ? $top['label'].' carries '.$this->peso((float) $top['value']).' — '.$share.'% of the '.$this->peso($pipeline).' open pipeline.'
                        : 'No open deals are on the board.',
                    'domain' => 'cost',
                    'href' => '/pipeline',
                    'link_label' => 'Open the board',
                ],
                [
                    'id' => 'stalled',
                    'title' => 'Deals that have not moved',
                    'body' => $stalled > 0
                        ? $stalled.' open '.$this->plural($stalled, 'deal').' '.$this->verb($stalled, 'has', 'have').' gone '.$stuckDays.' days without a touch.'
                        : 'Every open deal has moved inside '.$stuckDays.' days.',
                    'domain' => 'activity',
                    'href' => '/pipeline',
                    'link_label' => 'Review the board',
                ],
                [
                    'id' => 'win_rate',
                    'title' => 'Closed-deal outcome',
                    'body' => $closedTotal === 0
                        ? 'No deals closed this period, so the win rate is not yet meaningful.'
                        : $this->closed['won'].' won and '.$this->closed['lost'].' lost — a '.$this->number($winRate).'% win rate.',
                    'domain' => 'counts',
                    'href' => '/opportunities',
                    'link_label' => 'Open the deal book',
                ],
            ],
            'actions' => [
                [
                    'id' => 'open_board',
                    'label' => 'Open the pipeline board',
                    'description' => 'See every open deal by stage and move the work forward.',
                    'href' => '/pipeline',
                ],
                [
                    'id' => 'open_deals',
                    'label' => 'Review the deal book',
                    'description' => 'Sort the book by owner, close window or value.',
                    'href' => '/opportunities',
                ],
                [
                    'id' => 'chase_stalled',
                    'label' => 'Chase the stalled deals',
                    'description' => 'Pick the deals that have gone quiet and log a touch.',
                    'href' => '/pipeline',
                ],
                [
                    'id' => 'open_worklist',
                    'label' => 'Check the follow-up worklist',
                    'description' => 'Make sure the next step on each deal is owed and dated.',
                    'href' => '/follow-ups',
                ],
            ],
        ];
    }

    /**
     * “Which clients need care?”
     *
     * @return array{headline: string, narrative: string, insights: array<int, array<string, mixed>>, actions: array<int, array<string, mixed>>}
     */
    private function clientHealth(): array
    {
        $active = (int) $this->value('clients');
        $followUps = (int) $this->value('follow-ups');
        $atRisk = $this->signals->atRiskCompanies();
        $overdue = $this->signals->overdueFollowUps();
        $attention = $this->signals->unresolvedSurveys();
        $expiring = $this->signals->expiringContracts();
        $handoffs = $this->signals->failedHandoffs();
        $dormant = $this->companyCount(CompanyStatus::Dormant);
        $churned = $this->companyCount(CompanyStatus::Churned);
        $metrics = SurveyResponse::metrics(
            SurveyResponse::query()->visibleTo($this->user),
        );

        $csat = $metrics['csat'];
        $nps = $metrics['nps'];
        $responses = $metrics['responses'];

        $headline = $attention > 0
            ? $attention.' '.$this->plural($attention, 'client').' '.$this->verb($attention, 'needs', 'need').' a callback after a low score.'
            : ($atRisk > 0
                ? $atRisk.' '.$this->plural($atRisk, 'account').' '.$this->verb($atRisk, 'is', 'are').' marked at risk.'
                : 'No client needs a callback right now.');

        $narrative = $active.' active '.$this->plural($active, 'client').', '
            .$atRisk.' at risk, '.$dormant.' dormant and '.$churned.' churned. '
            .($responses === 0
                ? 'No survey answers are in view yet.'
                : 'Clients have answered '.$responses.' '.$this->plural($responses, 'survey').' with CSAT '
                    .($csat !== null ? $this->number($csat).' / 5' : '—')
                    .' and NPS '.($nps !== null ? (string) $nps : '—').'.')
            .' '.$attention.' unresolved '.$this->plural($attention, 'answer').' '
            .$this->verb($attention, 'is', 'are').' waiting for a callback. '
            .$expiring.' signed '.$this->plural($expiring, 'contract').' '
            .$this->verb($expiring, 'is', 'are').' inside the renewal window. '
            .$followUps.' '.$this->plural($followUps, 'follow-up').' owed; '.$overdue.' past due.'
            .($handoffs > 0 ? ' '.$handoffs.' Service '.$this->plural($handoffs, 'handoff').' failed.' : '');

        return [
            'headline' => $headline,
            'narrative' => $narrative,
            'insights' => [
                [
                    'id' => 'attention',
                    'title' => $attention > 0 ? 'The call-back queue' : 'No open call-backs',
                    'body' => $attention > 0
                        ? $attention.' unresolved '.$this->plural($attention, 'answer').' '.$this->verb($attention, 'needs', 'need').' a human to close the loop.'
                        : 'No survey answer is waiting for a callback.',
                    'domain' => 'people',
                    'href' => '/satisfaction/responses?attention=true',
                    'link_label' => 'Read the answers',
                ],
                [
                    'id' => 'csat',
                    'title' => 'How clients scored the work',
                    'body' => $responses === 0
                        ? 'No survey answers are in view yet.'
                        : 'CSAT '.($csat !== null ? $this->number($csat).' / 5' : '—')
                            .' and NPS '.($nps !== null ? (string) $nps : '—')
                            .' across '.$responses.' '.$this->plural($responses, 'answer').'.',
                    'domain' => 'counts',
                    'href' => '/satisfaction',
                    'link_label' => 'Open satisfaction',
                ],
                [
                    'id' => 'churn',
                    'title' => 'The accounts that need care',
                    'body' => $atRisk.' at risk, '.$dormant.' dormant and '.$churned.' churned in view.'
                        .($expiring > 0 ? ' '.$expiring.' '.$this->plural($expiring, 'contract').' expire soon.' : ''),
                    'domain' => 'activity',
                    'href' => '/clients',
                    'link_label' => 'Review clients',
                ],
            ],
            'actions' => [
                [
                    'id' => 'read_answers',
                    'label' => 'Read the unhappy answers',
                    'description' => 'See the low scores and close the loop with a note.',
                    'href' => '/satisfaction/responses?attention=true',
                ],
                [
                    'id' => 'open_satisfaction',
                    'label' => 'Open the satisfaction view',
                    'description' => 'Review CSAT, NPS and the latest client feedback.',
                    'href' => '/satisfaction',
                ],
                [
                    'id' => 'open_clients',
                    'label' => 'Review the client book',
                    'description' => 'Check lifecycle, renewals and the accounts at risk.',
                    'href' => '/clients',
                ],
                [
                    'id' => 'open_followups',
                    'label' => 'Clear the follow-up load',
                    'description' => 'Reschedule or complete what is past due.',
                    'href' => '/follow-ups',
                ],
            ],
        ];
    }

    /**
     * The open stage carrying the most value, if any.
     *
     * @return array{label: string, value: float, domain: string, href: string}|null
     */
    private function topStage(): ?array
    {
        $segments = $this->summary['donut']['segments'] ?? [];

        if ($segments === []) {
            return null;
        }

        usort($segments, fn (array $left, array $right): int => $right['value'] <=> $left['value']);

        return $segments[0];
    }

    /**
     * @return array{won: int, lost: int}
     */
    private function closedCounts(): array
    {
        $counts = Opportunity::query()
            ->visibleTo($this->user)
            ->whereIn('stage', [OpportunityStage::Won, OpportunityStage::Lost])
            ->whereBetween('actual_close_date', [$this->period->startDate(), $this->period->endDate()])
            ->selectRaw('stage, count(*) as aggregate')
            ->groupBy('stage')
            ->pluck('aggregate', 'stage');

        return [
            'won' => (int) ($counts[OpportunityStage::Won->value] ?? 0),
            'lost' => (int) ($counts[OpportunityStage::Lost->value] ?? 0),
        ];
    }

    private function companyCount(CompanyStatus $status): int
    {
        return Company::query()
            ->visibleTo($this->user)
            ->where('status', $status)
            ->count();
    }

    private function value(string $id): int|float
    {
        /** @var array<string, mixed>|null $kpi */
        $kpi = $this->kpis->get($id);

        return $kpi['value'] ?? 0;
    }

    private function attentionBody(int $surveys, int $atRisk): string
    {
        if ($surveys > 0 && $atRisk > 0) {
            return $surveys.' '.$this->plural($surveys, 'unhappy answer').' and '
                .$atRisk.' '.$this->plural($atRisk, 'at-risk account').' need a human.';
        }

        if ($surveys > 0) {
            return $surveys.' '.$this->plural($surveys, 'unhappy answer').' '
                .$this->verb($surveys, 'needs', 'need').' a callback.';
        }

        if ($atRisk > 0) {
            return $atRisk.' '.$this->plural($atRisk, 'account').' '
                .$this->verb($atRisk, 'is', 'are').' marked at risk.';
        }

        return 'No unhappy answers or at-risk accounts are waiting.';
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
}
