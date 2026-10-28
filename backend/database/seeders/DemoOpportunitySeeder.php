<?php

namespace Database\Seeders;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
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
 * Covers **every** pipeline stage so the board, the column totals and the forecast header
 * all have data: early enquiries, discovery, a proposal out, negotiation, a stalled deal on
 * hold, a won engagement and a lost one.
 */
class DemoOpportunitySeeder extends Seeder
{
    public function run(): void
    {
        $sam = User::where('email', 'salesrep@primepower.test')->firstOrFail();
        $liza = User::where('email', 'salesrep2@primepower.test')->firstOrFail();

        // Sunrise Foods Inc — early discovery plus a proposal the client is reviewing.
        $this->deal('Sunrise Foods Inc', $sam, [
            'name' => 'Sunrise Foods Inc — commissary crew',
            'stage' => OpportunityStage::Discovery,
            'amount' => 380000.00,
            'expected_close_date' => Carbon::now()->addWeeks(6),
            'next_step' => 'Site walk at the Balintawak commissary',
            'note' => 'Needs 12 packers for the holiday peak.',
            'ageDays' => 4,
            'touchDays' => 1,
        ]);

        $this->deal('Sunrise Foods Inc', $sam, [
            'name' => 'Sunrise Foods Inc — warehouse staffing',
            'stage' => OpportunityStage::ProposalSent,
            'amount' => 450000.00,
            'expected_close_date' => Carbon::now()->addWeeks(3),
            'next_step' => 'Follow up on the revised rate sheet',
            'note' => 'Proposal sent; awaiting procurement sign-off.',
            'ageDays' => 6,
            'touchDays' => 2,
        ]);

        // BPO Solutions PH — a live negotiation and an expansion that is parked for now.
        $this->deal('BPO Solutions PH', $sam, [
            'name' => 'BPO Solutions PH — 40-seat contact centre ramp',
            'stage' => OpportunityStage::Negotiation,
            'amount' => 14760000.00,
            'expected_close_date' => Carbon::now()->addWeeks(2),
            'next_step' => 'Legal review of the 90-day ramp-down clause',
            'note' => 'Down to commercial terms.',
            'ageDays' => 11,
            'touchDays' => 1,
        ]);

        $this->deal('BPO Solutions PH', $sam, [
            'name' => 'BPO Solutions PH — graveyard shift expansion',
            'stage' => OpportunityStage::OnHold,
            'amount' => 7380000.00,
            'expected_close_date' => Carbon::now()->addMonths(2),
            'next_step' => 'Revisit once the Ortigas ramp completes',
            'note' => 'Client deferred the second shift to next quarter.',
            'ageDays' => 18,
            'touchDays' => 12,
        ]);

        // Harbor Logistics — the won engagement plus the second-shift upsell.
        $this->deal('Harbor Logistics', $liza, [
            'name' => 'Harbor Logistics — second-shift operators',
            'stage' => OpportunityStage::Negotiation,
            'amount' => 1200000.00,
            'expected_close_date' => Carbon::now()->addWeeks(4),
            'next_step' => 'Confirm the shift differential with operations',
            'note' => 'Upsell on the live forklift pooling agreement.',
            'ageDays' => 9,
            'touchDays' => 3,
        ]);

        $this->deal('Harbor Logistics', $liza, [
            'name' => 'Harbor Logistics — driver pooling',
            'stage' => OpportunityStage::Won,
            'amount' => 2400000.00,
            'probability' => 100,
            'expected_close_date' => Carbon::now()->subWeeks(4),
            'actual_close_date' => Carbon::now()->subWeeks(4),
            'note' => 'Signed the 2026 forklift pooling agreement.',
            'ageDays' => 45,
            'touchDays' => 28,
        ]);

        // Greenfield Hotels — lost on price; Greenfield Cebu — a fresh enquiry and a qualified deal.
        $this->deal('Greenfield Hotels', $sam, [
            'name' => 'Greenfield Hotels — banquet crew outsourcing',
            'stage' => OpportunityStage::Lost,
            'amount' => 980000.00,
            'probability' => 0,
            'lost_reason' => LostReason::Price,
            'expected_close_date' => Carbon::now()->subWeek(),
            'actual_close_date' => Carbon::now()->subWeek(),
            'note' => 'Went with an in-house team on cost.',
            'ageDays' => 30,
            'touchDays' => 7,
        ]);

        $this->deal('Greenfield Hotels Cebu', $liza, [
            'name' => 'Greenfield Hotels Cebu — resort crew',
            'stage' => OpportunityStage::NewInquiry,
            'amount' => 250000.00,
            'expected_close_date' => Carbon::now()->addMonths(2),
            'next_step' => 'Qualify the headcount with the Cebu property',
            'note' => 'Inbound enquiry from the Cebu resort.',
            'ageDays' => 2,
            'touchDays' => 2,
        ]);

        $this->deal('Greenfield Hotels Cebu', $liza, [
            'name' => 'Greenfield Hotels Cebu — housekeeping crew',
            'stage' => OpportunityStage::Qualified,
            'amount' => 620000.00,
            'expected_close_date' => Carbon::now()->addMonth(),
            'next_step' => 'Book the discovery call',
            'note' => '20 housekeeping staff needed for launch.',
            'ageDays' => 3,
            'touchDays' => 1,
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
        $touchDays = $attributes['touchDays'] ?? $ageDays;
        $stage = $attributes['stage'];

        /** @var Opportunity $opportunity */
        $opportunity = $company->opportunities()->create([
            'lead_id' => $company->source_lead_id,
            'name' => $attributes['name'],
            'stage' => $stage,
            'amount' => $attributes['amount'] ?? null,
            'currency' => 'PHP',
            'probability' => $attributes['probability'] ?? $stage->defaultProbability(),
            'expected_close_date' => $attributes['expected_close_date'] ?? null,
            'actual_close_date' => $attributes['actual_close_date'] ?? null,
            'next_step' => $attributes['next_step'] ?? null,
            'lost_reason' => $attributes['lost_reason'] ?? null,
            'source' => $company->sourceLead?->source?->value,
            'primary_contact_id' => $company->contacts()->where('is_primary', true)->value('id'),
            'owner_id' => $owner->id,
        ]);

        // Back-date so the pipeline does not look freshly created and `days_in_stage` /
        // `is_stale` read believably (timestamps are not mass-assignable).
        $opportunity->forceFill([
            'created_at' => Carbon::now()->subDays($ageDays),
            'stage_changed_at' => Carbon::now()->subDays($touchDays),
            'last_activity_at' => Carbon::now()->subDays($touchDays),
        ])->save();

        // A short deal timeline so the detail page has history to show.
        $opportunity->activities()->create([
            'type' => ActivityType::Note,
            'subject' => 'Deal opened',
            'body' => $attributes['note'] ?? null,
            'status' => ActivityStatus::Done,
            'owner_id' => $owner->id,
            'occurred_at' => Carbon::now()->subDays($ageDays),
        ]);

        if ($stage === OpportunityStage::Won) {
            $opportunity->activities()->create([
                'type' => ActivityType::System,
                'subject' => 'Stage changed negotiation → won',
                'status' => ActivityStatus::Done,
                'owner_id' => $owner->id,
                'occurred_at' => Carbon::now()->subDays($touchDays),
            ]);
        }

        if ($stage === OpportunityStage::Lost) {
            $opportunity->activities()->create([
                'type' => ActivityType::System,
                'subject' => 'Stage changed negotiation → lost',
                'body' => 'Lost on price.',
                'status' => ActivityStatus::Done,
                'owner_id' => $owner->id,
                'occurred_at' => Carbon::now()->subDays($touchDays),
            ]);
        }
    }
}
