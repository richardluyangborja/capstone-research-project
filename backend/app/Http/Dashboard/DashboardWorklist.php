<?php

namespace App\Http\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\CompanyStatus;
use App\Enums\DashboardRange;
use App\Enums\HandoffStatus;
use App\Enums\LeadStatus;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\SurveyResponse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The dashboard's needs-attention worklist (spec 14 §Worklist, D2 phase 2).
 *
 * One query per source — unhappy answers, overdue tasks and scheduled messages, at-risk or
 * failed-handoff accounts, stalled deals, old unassigned leads and contracts inside the renewal
 * window — merged into one severity-ordered list. Nothing is stored: due labels and severities
 * are computed on read, and every source goes through its module's `visibleTo`. Rows the caller's
 * tier may not see are dropped here, never in the client.
 */
final class DashboardWorklist
{
    private const SEVERITY_RANK = ['danger' => 0, 'warning' => 1, 'info' => 2];

    private DashboardScope $scope;

    public function __construct(
        private readonly User $user,
        private readonly DashboardRange $range,
    ) {
        $this->scope = DashboardScope::for($user);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array{total: int, range: string}}
     */
    public function build(): array
    {
        $rows = [
            ...$this->surveys(),
            ...$this->overdueTasks(),
            ...$this->overdueMessages(),
            ...$this->accounts(),
            ...$this->stalledDeals(),
            ...$this->unassignedLeads(),
            ...$this->expiringContracts(),
        ];

        $rows = array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->scope->allows((string) $row['minTier']),
        ));

        usort($rows, function (array $left, array $right): int {
            $rank = self::SEVERITY_RANK[$left['due']['severity']] <=> self::SEVERITY_RANK[$right['due']['severity']];

            if ($rank !== 0) {
                return $rank;
            }

            $time = $left['sort_at'] <=> $right['sort_at'];

            return $time !== 0 ? $time : strcmp((string) $left['id'], (string) $right['id']);
        });

        $rows = array_map(function (array $row): array {
            unset($row['sort_at']);

            return $row;
        }, $rows);

        return [
            'data' => $rows,
            'meta' => [
                'total' => count($rows),
                'range' => $this->range->value,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function surveys(): array
    {
        return SurveyResponse::query()
            ->visibleTo($this->user)
            ->unresolvedAttention()
            ->with([
                'company:id,name,owner_id',
                'company.owner:id,name',
                'survey:id,name',
            ])
            ->get()
            ->filter(fn (SurveyResponse $response): bool => $response->company !== null)
            ->map(function (SurveyResponse $response): array {
                $parts = [];

                if ($response->csat !== null) {
                    $parts[] = 'CSAT '.$this->number((float) $response->csat);
                }

                if ($response->nps !== null) {
                    $parts[] = 'NPS '.$response->nps;
                }

                $detail = implode(' · ', $parts);

                if ($response->survey?->name !== null) {
                    $detail .= ($detail !== '' ? ' on the ' : '').$response->survey->name;
                }

                return $this->row(
                    id: 'survey:'.$response->id,
                    kind: 'survey',
                    subject: (string) $response->company?->name,
                    detail: $detail !== '' ? $detail : 'Answer needs a call-back.',
                    owner: $response->company?->owner,
                    due: [
                        'label' => $this->dateLabel($response->submitted_at),
                        'severity' => 'danger',
                    ],
                    href: '/satisfaction/responses/'.$response->id,
                    actionLabel: 'Read answer',
                    minTier: 'personal',
                    sortAt: $response->submitted_at?->timestamp ?? 0,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overdueTasks(): array
    {
        return Activity::query()
            ->visibleTo($this->user)
            ->tasks()
            ->where('status', ActivityStatus::Open)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->with(['subjectable', 'owner:id,name'])
            ->get()
            ->map(function (Activity $task): array {
                $record = $task->recordLabel();
                $subject = $record !== null && ! str_contains((string) $task->subject, $record)
                    ? $record.' — '.$task->subject
                    : (string) $task->subject;
                $body = trim((string) $task->body);
                $detail = $body !== ''
                    ? Str::limit($body, 140)
                    : ($record !== null ? 'Follow-up on '.$record : 'Follow-up task');

                return $this->row(
                    id: 'task:'.$task->id,
                    kind: 'follow_up',
                    subject: $subject,
                    detail: $detail,
                    owner: $task->owner,
                    due: [
                        'label' => $this->overdueLabel($task->due_at),
                        'severity' => 'danger',
                    ],
                    href: '/follow-ups/'.$task->id,
                    actionLabel: 'Open follow-up',
                    minTier: 'personal',
                    sortAt: $task->due_at?->timestamp ?? 0,
                );
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overdueMessages(): array
    {
        return Communication::query()
            ->visibleTo($this->user)
            ->scheduled()
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<', now())
            ->with(['subjectable', 'owner:id,name'])
            ->get()
            ->map(function (Communication $message): array {
                $recipient = $message->to_name ?: $message->to_address;
                $record = $message->recordLabel();
                $detail = $recipient !== null
                    ? 'Scheduled to '.$recipient
                    : ($record !== null ? 'Scheduled message on '.$record : 'Scheduled message');

                return $this->row(
                    id: 'message:'.$message->id,
                    kind: 'follow_up',
                    subject: (string) ($message->subject ?: 'Scheduled message'),
                    detail: $detail,
                    owner: $message->owner,
                    due: [
                        'label' => $this->overdueLabel($message->scheduled_for),
                        'severity' => 'danger',
                    ],
                    href: '/communications/'.$message->id,
                    actionLabel: 'Open message',
                    minTier: 'personal',
                    sortAt: $message->scheduled_for?->timestamp ?? 0,
                );
            })
            ->all();
    }

    /**
     * At-risk accounts and accounts whose mocked Service handoff failed. A company that is both
     * is one row, with the blocked handoff taking precedence.
     *
     * @return array<int, array<string, mixed>>
     */
    private function accounts(): array
    {
        return Company::query()
            ->visibleTo($this->user)
            ->where(function (Builder $query): void {
                $query->where('status', CompanyStatus::AtRisk)
                    ->orWhere('handoff_status', HandoffStatus::Failed);
            })
            ->with('owner:id,name')
            ->get()
            ->map(function (Company $company): array {
                $failed = $company->handoff_status === HandoffStatus::Failed;

                return $this->row(
                    id: 'company:'.$company->id.($failed ? ':handoff' : ':risk'),
                    kind: 'company',
                    subject: (string) $company->name,
                    detail: $failed
                        ? 'Service handoff failed — retry pending'
                        : 'Account marked at risk',
                    owner: $company->owner,
                    due: [
                        'label' => $failed ? 'Blocked' : 'At risk',
                        'severity' => $failed ? 'danger' : 'warning',
                    ],
                    href: '/companies/'.$company->id,
                    actionLabel: $failed ? 'Retry handoff' : 'Review account',
                    minTier: 'personal',
                    sortAt: $company->created_at?->timestamp ?? 0,
                );
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stalledDeals(): array
    {
        return Opportunity::query()
            ->visibleTo($this->user)
            ->open()
            ->whereRaw(
                'COALESCE(last_activity_at, stage_changed_at, created_at) <= ?',
                [now()->subDays((int) config('crm.pipeline.stuck_days', 21))->toDateTimeString()],
            )
            ->with(['company:id,name', 'owner:id,name'])
            ->get()
            ->map(function (Opportunity $opportunity): array {
                $stage = ucfirst(str_replace('_', ' ', $opportunity->stage->value));
                $days = $opportunity->daysSinceActivity();

                return $this->row(
                    id: 'opportunity:'.$opportunity->id,
                    kind: 'opportunity',
                    subject: (string) $opportunity->name,
                    detail: $this->peso((float) ($opportunity->amount ?? 0)).' · '.$days.' '.$this->plural($days, 'day').' without a touch in '.$stage,
                    owner: $opportunity->owner,
                    due: [
                        'label' => 'Stalled',
                        'severity' => 'warning',
                    ],
                    href: '/opportunities/'.$opportunity->id,
                    actionLabel: 'Open deal',
                    minTier: 'personal',
                    sortAt: ($opportunity->last_activity_at ?? $opportunity->stage_changed_at ?? $opportunity->created_at)?->timestamp ?? 0,
                );
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function unassignedLeads(): array
    {
        return Lead::query()
            ->visibleTo($this->user)
            ->whereNull('owner_id')
            ->where('status', '!=', LeadStatus::Archived)
            ->where('created_at', '<=', now()->subDay())
            ->get()
            ->map(function (Lead $lead): array {
                $days = max(1, (int) $lead->created_at?->diffInDays(now()));

                return $this->row(
                    id: 'lead:'.$lead->id.':unassigned',
                    kind: 'lead',
                    subject: (string) $lead->company_name,
                    detail: 'Unassigned lead awaiting first touch',
                    owner: null,
                    due: [
                        'label' => $days.' '.$this->plural($days, 'day'),
                        'severity' => 'warning',
                    ],
                    href: '/leads/'.$lead->id,
                    actionLabel: 'Claim lead',
                    minTier: 'team',
                    sortAt: $lead->created_at?->timestamp ?? 0,
                );
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expiringContracts(): array
    {
        $today = CarbonImmutable::now((string) config('crm.timezone', 'Asia/Manila'))->startOfDay();

        return Contract::query()
            ->visibleTo($this->user)
            ->signedExpiringSoon()
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $today->toDateString())
            ->with(['company:id,name,owner_id', 'company.owner:id,name', 'owner:id,name'])
            ->get()
            ->map(function (Contract $contract) use ($today): array {
                $timezone = (string) config('crm.timezone', 'Asia/Manila');
                $end = CarbonImmutable::instance($contract->end_date)->setTimezone($timezone)->startOfDay();
                $days = max(0, (int) $today->diffInDays($end));
                $company = $contract->company?->name;
                $subject = $company !== null ? $company.' — '.$contract->title : (string) $contract->title;

                return $this->row(
                    id: 'contract:'.$contract->id.':renewal',
                    kind: 'contract',
                    subject: $subject,
                    detail: 'Renewal due '.$end->format('j M'),
                    owner: $contract->owner ?? $contract->company?->owner,
                    due: [
                        'label' => match (true) {
                            $days === 0 => 'Ends today',
                            $days === 1 => '1 day',
                            default => $days.' days',
                        },
                        'severity' => $days <= 7 ? 'danger' : 'warning',
                    ],
                    href: '/companies/'.$contract->company_id.'?tab=contracts',
                    actionLabel: 'Open contract',
                    minTier: 'team',
                    sortAt: $end->timestamp,
                );
            })
            ->all();
    }

    /**
     * @param  array{label: string, severity: string}  $due
     * @return array<string, mixed>
     */
    private function row(
        string $id,
        string $kind,
        string $subject,
        string $detail,
        ?User $owner,
        array $due,
        string $href,
        string $actionLabel,
        string $minTier,
        int $sortAt,
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'subject' => $subject,
            'detail' => $detail,
            'owner' => $owner !== null ? [
                'id' => $owner->id,
                'name' => $owner->name,
            ] : null,
            'due' => $due,
            'href' => $href,
            'action_label' => $actionLabel,
            'minTier' => $minTier,
            'sort_at' => $sortAt,
        ];
    }

    private function dateLabel(?CarbonInterface $at): string
    {
        return $at !== null
            ? CarbonImmutable::instance($at)->setTimezone((string) config('crm.timezone', 'Asia/Manila'))->format('j M')
            : '—';
    }

    private function overdueLabel(?CarbonInterface $due): string
    {
        if ($due === null) {
            return 'Past due';
        }

        $timezone = (string) config('crm.timezone', 'Asia/Manila');
        $dueAt = CarbonImmutable::instance($due)->setTimezone($timezone);
        $days = max(0, (int) $dueAt->startOfDay()->diffInDays(CarbonImmutable::now($timezone)->startOfDay()));

        return match (true) {
            $days === 0 => 'Due today',
            $days === 1 => '1 day overdue',
            default => $days.' days overdue',
        };
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
}
