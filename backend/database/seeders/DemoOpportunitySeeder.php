<?php

namespace Database\Seeders;

use App\Enums\LostReason;
use App\Enums\OpportunityStage;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fixed demo deals (no faker — seeded for the proposal demo, see system-tech.md).
 *
 * One deal per demo company, covering the shapes the (read-only) Opportunities tab can
 * show: an early qualified deal, a proposal out, one in negotiation, a won engagement and
 * a lost one.
 */
class DemoOpportunitySeeder extends Seeder
{
    public function run(): void
    {
        $sam = User::where('email', 'salesrep@primepower.test')->firstOrFail();
        $liza = User::where('email', 'salesrep2@primepower.test')->firstOrFail();

        $this->deal('Sunrise Foods Inc', $sam, [
            'name' => 'Sunrise Foods Inc — warehouse staffing',
            'stage' => OpportunityStage::ProposalSent,
            'amount' => 450000.00,
            'probability' => 60,
            'expected_close_date' => Carbon::now()->addWeeks(3),
            'ageDays' => 6,
        ]);

        $this->deal('BPO Solutions PH', $sam, [
            'name' => 'BPO Solutions PH — 40-seat contact centre ramp',
            'stage' => OpportunityStage::Negotiation,
            'amount' => 1250000.00,
            'probability' => 80,
            'expected_close_date' => Carbon::now()->addWeeks(2),
            'ageDays' => 11,
        ]);

        $this->deal('Harbor Logistics', $liza, [
            'name' => 'Harbor Logistics — driver pooling',
            'stage' => OpportunityStage::Won,
            'amount' => 2400000.00,
            'probability' => 100,
            'expected_close_date' => Carbon::now()->subWeeks(4),
            'actual_close_date' => Carbon::now()->subWeeks(4),
            'ageDays' => 45,
        ]);

        $this->deal('Greenfield Hotels', $sam, [
            'name' => 'Greenfield Hotels — banquet crew outsourcing',
            'stage' => OpportunityStage::Lost,
            'amount' => 980000.00,
            'probability' => 0,
            'lost_reason' => LostReason::Price,
            'expected_close_date' => Carbon::now()->subWeek(),
            'actual_close_date' => Carbon::now()->subWeek(),
            'ageDays' => 30,
        ]);

        $this->deal('Greenfield Hotels Cebu', $liza, [
            'name' => 'Greenfield Hotels Cebu — housekeeping crew',
            'stage' => OpportunityStage::Qualified,
            'amount' => 620000.00,
            'probability' => 20,
            'expected_close_date' => Carbon::now()->addMonth(),
            'ageDays' => 3,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function deal(string $companyName, User $owner, array $attributes): void
    {
        $company = Company::where('name', $companyName)->first();

        if ($company === null) {
            return;
        }

        $ageDays = $attributes['ageDays'] ?? 0;
        unset($attributes['ageDays']);

        /** @var Opportunity $opportunity */
        $opportunity = $company->opportunities()->create([
            'lead_id' => $company->source_lead_id,
            'name' => $attributes['name'],
            'stage' => $attributes['stage'],
            'amount' => $attributes['amount'] ?? null,
            'currency' => 'PHP',
            'probability' => $attributes['probability'] ?? null,
            'expected_close_date' => $attributes['expected_close_date'] ?? null,
            'actual_close_date' => $attributes['actual_close_date'] ?? null,
            'lost_reason' => $attributes['lost_reason'] ?? null,
            'source' => $company->sourceLead?->source,
            'primary_contact_id' => $company->contacts()->where('is_primary', true)->value('id'),
            'owner_id' => $owner->id,
        ]);

        // Back-date so the pipeline does not look freshly created (timestamps are not
        // mass-assignable).
        $opportunity->forceFill(['created_at' => Carbon::now()->subDays($ageDays)])->save();
    }
}
