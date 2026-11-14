<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\AuditSeverity;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Integrations\Core2AppraisalSummary;
use App\Integrations\Core2EmployeeGateway;
use App\Integrations\Core2EmployeeProfile;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    private const SOURCE = 'Core 2 HR, Attendance & Payroll (read-only)';

    public function index(Request $request, Core2EmployeeGateway $core2): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['salesrep', 'manager'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'range' => ['nullable', Rule::in(['30d', 'quarter', 'ytd'])],
        ]);
        $actor = $request->user();
        $query = $this->visibleEmployees($actor)
            ->with('team:id,name')
            ->when($validated['search'] ?? null, fn (Builder $builder, string $search) => $builder->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->when($validated['role'] ?? null, fn (Builder $builder, string $role) => $builder->where('role', $role))
            ->orderBy('name');
        $period = $this->period($validated['range'] ?? '30d');
        $paginator = $query->paginate((int) ($validated['per_page'] ?? 20));
        $users = collect($paginator->items());
        $rows = $this->buildPerformance($users, $period);
        $quotas = $this->quotasFor($users);
        $attendanceUsers = $users->filter(fn (User $employee): bool => $actor->role !== Role::Salesrep || $employee->is($actor))->values();
        $attendance = $this->attendanceFor($core2, $attendanceUsers, $period);
        $data = $users->map(function (User $employee) use ($actor, $core2, $rows, $attendance, $quotas, $validated): EmployeeResource {
            $quota = $quotas[$employee->id];
            $employee->setAttribute('quota', $quota);
            $employee->setAttribute('quota_attainment', $this->quotaAttainment($rows[$employee->id]['won_value'] ?? 0, $quota, $validated['range'] ?? '30d'));
            $employee->setAttribute('performance', $rows[$employee->id] ?? $this->emptyPerformance());
            $employee->setAttribute('attendance', $attendance === null ? null : ($attendance[$employee->id] ?? null));
            $profile = $actor->role === Role::Salesrep && ! $employee->is($actor)
                ? null
                : $core2->profile($employee->id);
            $employee->setAttribute('core2_profile', $profile === null ? null : $this->profileArray($profile));

            return new EmployeeResource($employee);
        });

        return response()->json([
            'data' => $data->map(fn (EmployeeResource $resource): array => $resource->resolve($request))->values(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                ...$this->provenance($attendance !== null),
                'range' => $validated['range'] ?? '30d',
            ],
        ]);
    }

    public function leaderboard(Request $request, Core2EmployeeGateway $core2): JsonResponse
    {
        abort_unless($request->user()->role === Role::Manager || $request->user()->role->isAdmin(), 403);

        $request->merge(['per_page' => 100, 'range' => $request->query('range', '30d')]);

        return $this->index($request, $core2);
    }

    public function show(Request $request, User $employee, Core2EmployeeGateway $core2): JsonResponse
    {
        Gate::authorize('viewEmployee', $employee);
        $period = $this->period('30d');
        $employees = collect([$employee->load('team:id,name')]);
        $mayViewAttendance = $request->user()->role !== Role::Salesrep || $request->user()->is($employee);
        $attendance = $mayViewAttendance ? $this->attendanceFor($core2, $employees, $period) : null;
        $quota = $this->quotaFor($employee);
        $employee->setAttribute('quota', $quota);
        $employee->setAttribute('quota_attainment', $this->quotaAttainment($this->buildPerformance($employees, $period)[$employee->id]['won_value'] ?? 0, $quota, '30d'));
        $employee->setAttribute('performance', $this->buildPerformance($employees, $period)[$employee->id] ?? $this->emptyPerformance());
        $employee->setAttribute('attendance', $attendance === null ? null : ($attendance[$employee->id] ?? null));
        $profile = $request->user()->role === Role::Salesrep && ! $request->user()->is($employee)
            ? null
            : $core2->profile($employee->id);
        $employee->setAttribute('core2_profile', $profile === null ? null : $this->profileArray($profile));

        return response()->json([
            'data' => (new EmployeeResource($employee))->resolve($request),
            'meta' => $this->provenance($attendance !== null),
        ]);
    }

    public function updateQuota(Request $request, User $employee): JsonResponse
    {
        Gate::authorize('updateEmployeeQuota', $employee);
        $unknown = array_diff(array_keys($request->all()), ['monthly_quota', 'quarterly_quota']);
        if ($unknown !== []) {
            abort(422, 'Only monthly_quota and quarterly_quota are accepted.');
        }

        $validated = $request->validate([
            'monthly_quota' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'quarterly_quota' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        if ($validated === []) {
            abort(422, 'Provide at least one quota value.');
        }

        $before = $this->quotaFor($employee);
        foreach (['monthly' => 'monthly_quota', 'quarterly' => 'quarterly_quota'] as $period => $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $key = "crm.quota.{$period}_php";
            $value = $validated[$field] === null ? null : (float) $validated[$field];
            if ($value === null || $value === (float) config('crm.employees.quota_default_php', 500000)) {
                Setting::query()->where('scope', 'user')->where('scope_id', $employee->id)->where('key', $key)->delete();
            } else {
                Setting::query()->updateOrCreate(
                    ['scope' => 'user', 'scope_id' => $employee->id, 'key' => $key],
                    ['value' => $value, 'updated_by' => $request->user()->id],
                );
            }
        }

        $after = $this->quotaFor($employee);
        if ($before !== $after) {
            AuditLog::record('employee.quota_updated', AuditSeverity::Info, $request->user()->id, User::class, $employee->id, $before, $after);
        }

        return response()->json(['data' => $after]);
    }

    public function performance(Request $request, User $employee): JsonResponse
    {
        Gate::authorize('viewEmployee', $employee);
        $validated = $request->validate(['range' => ['nullable', Rule::in(['30d', 'quarter', 'ytd'])]]);
        $range = (string) ($validated['range'] ?? '30d');
        $period = $this->period($range);

        return response()->json([
            'data' => $this->buildPerformance(collect([$employee]), $period)[$employee->id] ?? $this->emptyPerformance(),
            'meta' => ['range' => $range],
        ]);
    }

    public function attendance(Request $request, User $employee, Core2EmployeeGateway $core2): JsonResponse
    {
        Gate::authorize('viewEmployeeAttendance', $employee);
        $validated = $request->validate(['range' => ['nullable', Rule::in(['30d', 'quarter', 'ytd'])]]);
        $range = (string) ($validated['range'] ?? '30d');
        $period = $this->period($range);
        $summary = $core2->attendance($employee->id, $period[0]->toDateString(), $period[1]->toDateString());

        return response()->json([
            'data' => $summary === null ? null : [
                'user_id' => $employee->public_id,
                'attendance_rate' => $summary->attendanceRate,
                'days_present' => $summary->daysPresent,
                'late_arrivals_count' => $summary->lateArrivalsCount,
                'late_arrivals_minutes' => $summary->lateArrivalsMinutes,
                'undertime_hours' => $summary->undertimeHours,
                'absences_count' => $summary->absencesCount,
                'leave_balances' => $summary->leaveBalances,
            ],
            'meta' => $this->provenance($summary !== null) + ['range' => $range],
        ]);
    }

    public function compensation(Request $request, User $employee, Core2EmployeeGateway $core2): JsonResponse
    {
        Gate::authorize('viewEmployeeCompensation', $employee);
        $validated = $request->validate(['range' => ['nullable', Rule::in(['30d', 'quarter', 'ytd'])]]);
        $range = (string) ($validated['range'] ?? '30d');
        $period = $this->period($range);
        $summary = $core2->compensation($employee->id);
        $wonValue = (float) Opportunity::query()
            ->where('owner_id', $employee->id)
            ->where('stage', OpportunityStage::Won)
            ->whereBetween('actual_close_date', [$period[0]->toDateString(), $period[1]->toDateString()])
            ->sum('amount');
        $quotaTargets = $this->quotaFor($employee);
        $quotaValue = $quotaTargets[$range === 'quarter' ? 'quarterly' : 'monthly'];
        $baselineRate = (float) config('crm.employees.commission_rate_baseline_percent', 2.0);
        $acceleratorRate = (float) config('crm.employees.commission_rate_accelerator_percent', 3.5);
        $baselineBase = $quotaValue > 0 ? min($wonValue, $quotaValue) : $wonValue;
        $acceleratorBase = $quotaValue > 0 ? max(0, $wonValue - $quotaValue) : 0;
        $baselineCommission = round($baselineBase * $baselineRate / 100, 2);
        $acceleratorCommission = round($acceleratorBase * $acceleratorRate / 100, 2);
        $earnedCommission = round($baselineCommission + $acceleratorCommission, 2);
        $attainment = $quotaValue > 0 ? round($wonValue / $quotaValue * 100, 1) : null;
        $masked = $summary !== null
            && $request->user()->role === Role::Manager
            && ! $request->user()->is($employee)
            && (bool) config('crm.employees.salary_mask_for_managers', true);

        return response()->json([
            'data' => [
                'user_id' => $employee->public_id,
                'base_salary' => $summary === null || $masked ? null : $summary->baseSalary,
                'fixed_allowances' => $summary === null || $masked ? null : $summary->fixedAllowances,
                'is_masked' => $masked,
                'recent_payslips' => $summary === null ? [] : ($masked
                    ? array_map(fn (array $payslip): array => [...$payslip, 'gross_pay' => null, 'net_pay' => null], $summary->recentPayslips)
                    : $summary->recentPayslips),
                'commission' => [
                    'won_value' => $wonValue,
                    'quota_value' => $quotaValue,
                    'attainment_percent' => $attainment,
                    'baseline_rate_percent' => $baselineRate,
                    'accelerator_rate_percent' => $acceleratorRate,
                    'baseline_commission' => $baselineCommission,
                    'accelerator_commission' => $acceleratorCommission,
                    'total_earned_commission' => $earnedCommission,
                    'tier_applied' => $acceleratorBase > 0 ? 'accelerator' : 'baseline',
                ],
            ],
            'meta' => $this->provenance($summary !== null) + ['range' => $range],
        ]);
    }

    public function appraisals(Request $request, User $employee, Core2EmployeeGateway $core2): JsonResponse
    {
        Gate::authorize('viewEmployeeAppraisals', $employee);
        $summaries = $core2->appraisals($employee->id);

        return response()->json([
            'data' => $summaries === null ? null : array_map(fn (Core2AppraisalSummary $summary): array => [
                'appraisal_id' => $summary->appraisalId,
                'period_label' => $summary->periodLabel,
                'overall_rating' => $summary->overallRating,
                'competencies' => $summary->competencies,
                'evaluator_notes' => $summary->evaluatorNotes,
                'conducted_at' => $summary->conductedAt,
            ], $summaries),
            'meta' => $this->provenance($summaries !== null),
        ]);
    }

    private function visibleEmployees(User $actor): Builder
    {
        $query = User::query()->whereIn('role', [Role::Salesrep->value, Role::Manager->value]);

        if ($actor->role->isAdmin()) {
            return $query;
        }

        if ($actor->role === Role::Manager && $actor->team_id !== null) {
            return $query->where('team_id', $actor->team_id);
        }

        if ($actor->role === Role::Salesrep && $actor->team_id !== null) {
            return $query->where('team_id', $actor->team_id);
        }

        return $query->whereKey($actor->id);
    }

    /** @param Collection<int, User> $employees
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $period
     * @return array<int, array<string, int|float>>
     */
    private function buildPerformance(Collection $employees, array $period): array
    {
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        [$start, $end] = $period;
        $leads = Lead::query()->whereIn('owner_id', $ids)->where('status', '!=', LeadStatus::Archived)
            ->whereBetween('created_at', [$start->utc(), $end->utc()])->selectRaw('owner_id, count(*) as n')->groupBy('owner_id')->pluck('n', 'owner_id');
        $deals = Opportunity::query()->whereIn('owner_id', $ids)
            ->whereBetween('actual_close_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('owner_id, sum(case when stage = ? then 1 else 0 end) as won, sum(case when stage = ? then 1 else 0 end) as lost, coalesce(sum(case when stage = ? then amount else 0 end), 0) as won_value', [OpportunityStage::Won->value, OpportunityStage::Lost->value, OpportunityStage::Won->value])
            ->groupBy('owner_id')->get()->keyBy('owner_id');
        $open = Opportunity::query()->whereIn('owner_id', $ids)->whereNotIn('stage', [OpportunityStage::Won->value, OpportunityStage::Lost->value])
            ->selectRaw('owner_id, count(*) as count, coalesce(sum(amount), 0) as value')->groupBy('owner_id')->get()->keyBy('owner_id');
        $tasks = Activity::query()->tasks()->whereIn('owner_id', $ids)->whereBetween('due_at', [$start->utc(), $end->utc()])
            ->selectRaw('owner_id, count(*) as total, sum(case when status = ? and updated_at <= due_at then 1 else 0 end) as on_time', [ActivityStatus::Done->value])->groupBy('owner_id')->get()->keyBy('owner_id');
        $result = [];
        foreach ($employees as $employee) {
            $closed = $deals[$employee->id] ?? null;
            $openDeals = $open[$employee->id] ?? null;
            $followups = $tasks[$employee->id] ?? null;
            $won = (int) ($closed?->won ?? 0);
            $lost = (int) ($closed?->lost ?? 0);
            $totalTasks = (int) ($followups?->total ?? 0);
            $result[$employee->id] = [
                'leads_handled' => (int) ($leads[$employee->id] ?? 0),
                'deals_won' => $won,
                'won_value' => (float) ($closed?->won_value ?? 0),
                'conversion_rate' => $won + $lost === 0 ? 0.0 : round($won / ($won + $lost) * 100, 1),
                'open_pipeline_count' => (int) ($openDeals?->count ?? 0),
                'open_pipeline_value' => (float) ($openDeals?->value ?? 0),
                'follow_up_on_time' => $totalTasks === 0 ? 100.0 : round((int) $followups->on_time / $totalTasks * 100, 1),
            ];
        }

        return $result;
    }

    private function attendanceFor(Core2EmployeeGateway $core2, Collection $employees, array $period): ?array
    {
        $result = [];
        foreach ($employees as $employee) {
            $summary = $core2->attendance($employee->id, $period[0]->toDateString(), $period[1]->toDateString());
            if ($summary === null) {
                return null;
            }
            $result[$employee->id] = ['attendance_rate' => $summary->attendanceRate, 'late_arrivals_count' => $summary->lateArrivalsCount];
        }

        return $result;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(string $range): array
    {
        $end = CarbonImmutable::now((string) config('crm.timezone', 'Asia/Manila'));
        $start = match ($range) {
            'quarter' => $end->subDays(89)->startOfDay(),
            'ytd' => $end->startOfYear(),
            default => $end->subDays(29)->startOfDay(),
        };

        return [$start, $end];
    }

    /** @param Collection<int, User> $employees
     * @return array<int, array{monthly: float, quarterly: float}>
     */
    private function quotasFor(Collection $employees): array
    {
        $employeeIds = $employees->pluck('id')->all();
        $overrides = Setting::query()->where('scope', 'user')->whereIn('scope_id', $employeeIds)
            ->whereIn('key', ['crm.quota.monthly_php', 'crm.quota.quarterly_php'])
            ->get(['scope_id', 'key', 'value'])->groupBy('scope_id');
        $default = (float) config('crm.employees.quota_default_php', 500000);
        $quotas = [];

        foreach ($employeeIds as $employeeId) {
            $rows = $overrides->get($employeeId, collect())->keyBy('key');
            $quotas[$employeeId] = [
                'monthly' => (float) ($rows->get('crm.quota.monthly_php')?->value ?? $default),
                'quarterly' => (float) ($rows->get('crm.quota.quarterly_php')?->value ?? $default),
            ];
        }

        return $quotas;
    }

    /** @return array{monthly: float, quarterly: float} */
    private function quotaFor(User $employee): array
    {
        return $this->quotasFor(collect([$employee]))[$employee->id];
    }

    private function quotaAttainment(float $wonValue, array $quotaTargets, string $range): ?float
    {
        $quota = $quotaTargets[$range === 'quarter' ? 'quarterly' : 'monthly'];

        return $quota <= 0 ? null : round($wonValue / $quota * 100, 1);
    }

    private function profileArray(Core2EmployeeProfile $profile): array
    {
        return [
            'employee_reference' => $profile->employeeReference,
            'official_title' => $profile->officialTitle,
            'department' => $profile->department,
            'employment_status' => $profile->employmentStatus,
            'tenure_months' => $profile->tenureMonths,
            'shift_schedule' => $profile->shiftSchedule,
            'direct_manager' => $profile->directManager,
        ];
    }

    /** @return array<string, int|float> */
    private function emptyPerformance(): array
    {
        return ['leads_handled' => 0, 'deals_won' => 0, 'won_value' => 0.0, 'conversion_rate' => 0.0, 'open_pipeline_count' => 0, 'open_pipeline_value' => 0.0, 'follow_up_on_time' => 100.0];
    }

    /** @return array{source: string, read_only: bool, as_of: string, core2_available: bool} */
    private function provenance(bool $available): array
    {
        return ['source' => self::SOURCE, 'read_only' => true, 'as_of' => now()->toIso8601String(), 'core2_available' => $available];
    }
}
