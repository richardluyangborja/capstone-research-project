<?php

namespace Tests\Feature;

use App\Enums\OpportunityStage;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_commission_uses_baseline_rate_below_quota_and_reports_breakdown(): void
    {
        config(['crm.employees.commission_rate_baseline_percent' => 2, 'crm.employees.commission_rate_accelerator_percent' => 3.5]);
        $employee = User::factory()->salesrep()->create();
        $this->setWonRevenue($employee, 400000);

        $commission = $this->actingAs($employee, 'api')
            ->getJson("/api/v1/employees/{$employee->public_id}/compensation")
            ->assertOk()->json('data.commission');

        $this->assertEquals(400000, $commission['won_value']);
        $this->assertEquals(500000, $commission['quota_value']);
        $this->assertEquals(80, $commission['attainment_percent']);
        $this->assertEquals(8000, $commission['baseline_commission']);
        $this->assertEquals(0, $commission['accelerator_commission']);
        $this->assertEquals(8000, $commission['total_earned_commission']);
        $this->assertSame('baseline', $commission['tier_applied']);
    }

    public function test_commission_at_quota_has_no_accelerator_component(): void
    {
        $employee = User::factory()->salesrep()->create();
        $this->setWonRevenue($employee, 500000);

        $commission = $this->actingAs($employee, 'api')
            ->getJson("/api/v1/employees/{$employee->public_id}/compensation")
            ->assertOk()->json('data.commission');

        $this->assertEquals(100, $commission['attainment_percent']);
        $this->assertEquals(10000, $commission['baseline_commission']);
        $this->assertEquals(0, $commission['accelerator_commission']);
        $this->assertSame('baseline', $commission['tier_applied']);
    }

    public function test_commission_applies_accelerator_rate_only_to_revenue_above_quota(): void
    {
        config(['crm.employees.commission_rate_baseline_percent' => 2, 'crm.employees.commission_rate_accelerator_percent' => 3.5]);
        $employee = User::factory()->salesrep()->create();
        $this->setWonRevenue($employee, 1250000);

        $commission = $this->actingAs($employee, 'api')
            ->getJson("/api/v1/employees/{$employee->public_id}/compensation")
            ->assertOk()->json('data.commission');

        $this->assertEquals(10000, $commission['baseline_commission']);
        $this->assertEquals(26250, $commission['accelerator_commission']);
        $this->assertEquals(36250, $commission['total_earned_commission']);
        $this->assertSame('accelerator', $commission['tier_applied']);
    }

    public function test_zero_quota_uses_baseline_rate_on_all_won_revenue(): void
    {
        config(['crm.employees.commission_rate_baseline_percent' => 2, 'crm.employees.commission_rate_accelerator_percent' => 3.5]);
        $employee = User::factory()->salesrep()->create();
        Setting::query()->create(['scope' => 'user', 'scope_id' => $employee->id, 'key' => 'crm.quota.monthly_php', 'value' => 0]);
        $this->setWonRevenue($employee, 100000);

        $commission = $this->actingAs($employee, 'api')
            ->getJson("/api/v1/employees/{$employee->public_id}/compensation")
            ->assertOk()->json('data.commission');

        $this->assertNull($commission['attainment_percent']);
        $this->assertEquals(2000, $commission['baseline_commission']);
        $this->assertEquals(0, $commission['accelerator_commission']);
        $this->assertEquals(2000, $commission['total_earned_commission']);
    }

    public function test_quarter_range_uses_quarterly_quota(): void
    {
        $employee = User::factory()->salesrep()->create();
        Setting::query()->create(['scope' => 'user', 'scope_id' => $employee->id, 'key' => 'crm.quota.quarterly_php', 'value' => 1000000]);
        $this->setWonRevenue($employee, 750000);

        $commission = $this->actingAs($employee, 'api')
            ->getJson("/api/v1/employees/{$employee->public_id}/compensation?range=quarter")
            ->assertOk()->json('data.commission');

        $this->assertEquals(1000000, $commission['quota_value']);
        $this->assertEquals(75, $commission['attainment_percent']);
    }

    private function setWonRevenue(User $employee, float $amount): void
    {
        $company = Company::factory()->client()->ownedBy($employee)->create();
        $deal = Opportunity::factory()->forCompany($company)->ownedBy($employee)->stage(OpportunityStage::Won)->create([
            'amount' => $amount,
        ]);
        DB::table('opportunities')->where('id', $deal->id)->update([
            'stage' => OpportunityStage::Won->value,
            'actual_close_date' => now()->toDateString(),
        ]);
    }
}
