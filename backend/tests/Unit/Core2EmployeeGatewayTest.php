<?php

namespace Tests\Unit;

use App\Integrations\Core2EmployeeGateway;
use App\Integrations\Core2EmployeeProfile;
use App\Integrations\MockCore2EmployeeGateway;
use Tests\TestCase;

class Core2EmployeeGatewayTest extends TestCase
{
    public function test_mock_returns_deterministic_read_only_projection_for_a_user(): void
    {
        $gateway = new MockCore2EmployeeGateway;

        $profile = $gateway->profile(42);
        $attendance = $gateway->attendance(42, '2026-10-01', '2026-10-31');
        $compensation = $gateway->compensation(42);
        $appraisals = $gateway->appraisals(42);

        $this->assertInstanceOf(Core2EmployeeProfile::class, $profile);
        $this->assertSame('EMP-2026-'.sprintf('%04d', (crc32('core2-employee|42') % 9000) + 1000), $profile->employeeReference);
        $this->assertEquals($profile, $gateway->profile(42));
        $this->assertEquals($attendance, $gateway->attendance(42, '2026-10-01', '2026-10-31'));
        $this->assertEquals($compensation, $gateway->compensation(42));
        $this->assertEquals($appraisals, $gateway->appraisals(42));
        $this->assertSame(42, $attendance->userId);
        $this->assertSame(42, $compensation->userId);
        $this->assertIsArray($attendance->leaveBalances);
        $this->assertNotEmpty($appraisals);
        $this->assertGreaterThanOrEqual(0.0, $attendance->attendanceRate);
        $this->assertLessThanOrEqual(100.0, $attendance->attendanceRate);
    }

    public function test_failure_simulation_returns_null_for_every_projection_without_throwing(): void
    {
        config(['crm.integrations.core2_employees.simulate_failure' => true]);
        $gateway = new MockCore2EmployeeGateway;

        $this->assertNull($gateway->profile(42));
        $this->assertNull($gateway->attendance(42, '2026-10-01', '2026-10-31'));
        $this->assertNull($gateway->compensation(42));
        $this->assertNull($gateway->appraisals(42));
    }

    public function test_container_resolves_core2_gateway_to_deterministic_mock(): void
    {
        $this->assertInstanceOf(MockCore2EmployeeGateway::class, $this->app->make(Core2EmployeeGateway::class));
    }
}
