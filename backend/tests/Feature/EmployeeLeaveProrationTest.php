<?php

namespace Tests\Feature;

use App\Integrations\Core2AttendanceSummary;
use App\Integrations\Core2CompensationProfile;
use App\Integrations\Core2EmployeeGateway;
use App\Integrations\Core2EmployeeProfile;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeLeaveProrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_rate_and_quota_are_prorated_by_approved_leave_days(): void
    {
        $employee = User::factory()->salesrep()->create();
        Setting::query()->create(['scope' => 'user', 'scope_id' => $employee->id, 'key' => 'crm.quota.monthly_php', 'value' => 500000]);
        $this->app->instance(Core2EmployeeGateway::class, $this->gatewayWithAttendance($employee->id, 20, 2));
        $weekdays = $this->weekdaysInDefaultPeriod();

        $this->actingAs($employee, 'api')
            ->getJson("/api/v1/employees/{$employee->public_id}")
            ->assertOk()
            ->assertJsonPath('data.attendance.attendance_rate', 100)
            ->assertJsonPath('data.attendance.approved_leave_days', 2)
            ->assertJsonPath('data.adjusted_quota', round(500000 * ($weekdays - 2) / $weekdays, 2));
    }

    public function test_profile_prorates_the_quota_of_the_selected_range(): void
    {
        $employee = User::factory()->salesrep()->create();
        Setting::query()->create(['scope' => 'user', 'scope_id' => $employee->id, 'key' => 'crm.quota.monthly_php', 'value' => 500000]);
        Setting::query()->create(['scope' => 'user', 'scope_id' => $employee->id, 'key' => 'crm.quota.quarterly_php', 'value' => 1500000]);
        $this->app->instance(Core2EmployeeGateway::class, $this->gatewayWithAttendance($employee->id, 20, 0));

        $this->actingAs($employee, 'api')
            ->getJson("/api/v1/employees/{$employee->public_id}?range=quarter")
            ->assertOk()
            ->assertJsonPath('data.adjusted_quota', 1500000)
            ->assertJsonPath('meta.range', 'quarter');

        $this->getJson("/api/v1/employees/{$employee->public_id}?range=ytd")
            ->assertOk()
            ->assertJsonPath('data.adjusted_quota', 500000)
            ->assertJsonPath('meta.range', 'ytd');

        $this->getJson("/api/v1/employees/{$employee->public_id}")
            ->assertOk()
            ->assertJsonPath('data.adjusted_quota', 500000)
            ->assertJsonPath('meta.range', '30d');
    }

    public function test_attendance_percentage_is_capped_when_days_present_exceed_net_weekdays(): void
    {
        $employee = User::factory()->salesrep()->create();
        $this->app->instance(Core2EmployeeGateway::class, $this->gatewayWithAttendance($employee->id, 24, 2));

        $this->actingAs($employee, 'api')
            ->getJson("/api/v1/employees/{$employee->public_id}/attendance")
            ->assertOk()
            ->assertJsonPath('data.attendance_rate', 100);
    }

    public function test_zero_net_weekdays_uses_one_as_safe_denominator(): void
    {
        $employee = User::factory()->salesrep()->create();
        $this->app->instance(Core2EmployeeGateway::class, $this->gatewayWithAttendance($employee->id, 2, (float) $this->weekdaysInDefaultPeriod()));

        $this->actingAs($employee, 'api')
            ->getJson("/api/v1/employees/{$employee->public_id}/attendance")
            ->assertOk()
            ->assertJsonPath('data.net_working_days', 1)
            ->assertJsonPath('data.attendance_rate', 100);
    }

    /**
     * Weekday count of the controller's default 30-day period, so proration expectations
     * follow the rolling window instead of a fixed date-dependent constant.
     */
    private function weekdaysInDefaultPeriod(): int
    {
        $from = now('Asia/Manila')->subDays(29)->startOfDay();
        $to = now('Asia/Manila')->endOfDay();
        $weekdays = 0;
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            if ($date->isWeekday()) {
                $weekdays++;
            }
        }

        return $weekdays;
    }

    private function gatewayWithAttendance(int $userId, int $daysPresent, float $leaveDays): Core2EmployeeGateway
    {
        return new class($userId, $daysPresent, $leaveDays) implements Core2EmployeeGateway
        {
            public function __construct(private readonly int $userId, private readonly int $daysPresent, private readonly float $leaveDays) {}

            public function profile(int $userId): ?Core2EmployeeProfile
            {
                return null;
            }

            public function attendance(int $userId, string $from, string $to): ?Core2AttendanceSummary
            {
                return new Core2AttendanceSummary(
                    userId: $this->userId,
                    attendanceRate: 80,
                    daysPresent: $this->daysPresent,
                    lateArrivalsCount: 1,
                    lateArrivalsMinutes: 10,
                    undertimeHours: 0,
                    absencesCount: 2,
                    approvedLeaveDays: $this->leaveDays,
                    leaveBalances: ['vacation_leave_remaining' => 2, 'sick_leave_remaining' => 2, 'emergency_leave_remaining' => 1],
                );
            }

            public function compensation(int $userId): ?Core2CompensationProfile
            {
                return null;
            }

            public function appraisals(int $userId): ?array
            {
                return null;
            }
        };
    }
}
