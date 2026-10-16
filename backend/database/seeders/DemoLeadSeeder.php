<?php

namespace Database\Seeders;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\LeadRating;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Urgency;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fixed demo pipeline (no faker — seeded for the proposal demo, see system-tech.md).
 *
 * Covers every lifecycle state the UI can show: a healthy new lead, a stale lead, an
 * archive-due lead, an unassigned queue, two owners, and qualification history.
 */
class DemoLeadSeeder extends Seeder
{
    public function run(): void
    {
        $sam = User::where('email', 'salesrep@primepower.test')->firstOrFail();
        $liza = User::where('email', 'salesrep2@primepower.test')->firstOrFail();

        $sunrise = Lead::create([
            'company_name' => 'Sunrise Foods Inc',
            'contact_name' => 'Ana Reyes',
            'contact_email' => 'ana.reyes@sunrisefoods.ph',
            'contact_phone' => '+63 917 555 0101',
            'contact_position' => 'HR Supervisor',
            'source' => LeadSource::WalkIn,
            'industry' => 'Food Manufacturing',
            'location' => 'Quezon City',
            'service_interest' => 'Warehouse & production staff',
            'estimated_headcount' => 25,
            'needed_roles' => 'Warehouse pickers, packers',
            'urgency' => Urgency::High,
            'notes' => 'Walked in after the job fair; needs staff before the holiday peak.',
        ]);
        $this->backdate($sunrise, createdDaysAgo: 2);

        $metro = Lead::create([
            'company_name' => 'Metro Builders Corp',
            'contact_name' => 'Ben Cruz',
            'contact_email' => 'projects@metrobuilders.ph',
            'contact_phone' => '+63 918 555 0144',
            'contact_position' => 'Site Engineer',
            'source' => LeadSource::Website,
            'industry' => 'Construction',
            'location' => 'Taguig',
            'service_interest' => 'Skilled construction crew',
            'notes' => 'Website enquiry — nobody has picked this up yet.',
        ]);
        // Left to age so the lifecycle worklist has an "at risk" row.
        $this->backdate($metro, createdDaysAgo: 35);

        $bpo = Lead::create([
            'company_name' => 'BPO Solutions PH',
            'contact_name' => 'Carla Dizon',
            'contact_email' => 'carla@bposolutions.ph',
            'contact_phone' => '+63 917 555 0177',
            'contact_position' => 'Talent Acquisition Lead',
            'source' => LeadSource::Referral,
            'source_detail' => 'Referred by Greenfield Hotels',
            'industry' => 'Business Process Outsourcing',
            'location' => 'Ortigas Center',
            'service_interest' => 'Customer service representatives',
            'estimated_headcount' => 40,
            'needed_roles' => 'CSRs, team leads, QA analysts',
            'target_start_date' => now()->addWeeks(3)->toDateString(),
            'authority_confirmed' => true,
            'work_site' => 'Ortigas Center, Pasig',
            'urgency' => Urgency::High,
            'rating' => LeadRating::Warm,
            'owner_id' => $sam->id,
        ]);
        $this->backdate($bpo, createdDaysAgo: 8);

        $this->logActivity($bpo, $sam, ActivityType::Call, 'Discovery call with Carla', 'Discussed headcount and the ramp-up schedule.');
        $bpo->transitionTo(LeadStatus::Contacted, $sam, 'First outbound activity logged.');

        $harbor = Lead::create([
            'company_name' => 'Harbor Logistics',
            'contact_name' => 'Dan Torres',
            'contact_email' => 'ops@harborlogistics.ph',
            'contact_phone' => '+63 920 555 0122',
            'contact_position' => 'Operations Manager',
            'source' => LeadSource::Event,
            'source_detail' => 'Supply chain expo',
            'industry' => 'Logistics',
            'location' => 'Manila',
            'service_interest' => 'Forklift operators and dispatchers',
            'estimated_headcount' => 15,
            'needed_roles' => 'Forklift operators, dispatchers',
            'target_start_date' => now()->addMonth()->toDateString(),
            'authority_confirmed' => true,
            'work_site' => 'Manila South Harbor',
            'budget_rate' => 780,
            'urgency' => Urgency::Medium,
            'owner_id' => $liza->id,
        ]);
        $this->backdate($harbor, createdDaysAgo: 21);

        $this->logActivity($harbor, $liza, ActivityType::Email, 'Sent rate card', 'Shared the 2026 rate card and availability.');
        $harbor->transitionTo(LeadStatus::Contacted, $liza, 'First outbound activity logged.');
        $harbor->transitionTo(LeadStatus::Qualified, $liza, 'Budget and roles confirmed.');
        $this->backdate($harbor, activityDaysAgo: 16);

        $greenfield = Lead::create([
            'company_name' => 'Greenfield Hotels',
            'contact_name' => 'Elena Santos',
            'contact_email' => 'hr@greenfieldhotels.ph',
            'contact_phone' => '+63 917 555 0199',
            'contact_position' => 'HR Director',
            'source' => LeadSource::Partner,
            'industry' => 'Hospitality',
            'location' => 'Makati',
            'service_interest' => 'Housekeeping and F&B staff',
            'estimated_headcount' => 60,
            'needed_roles' => 'Housekeeping, F&B attendants',
            'target_start_date' => now()->addMonths(2)->toDateString(),
            'authority_confirmed' => true,
            'work_site' => 'Makati CBD',
            'urgency' => Urgency::Medium,
            'owner_id' => $sam->id,
        ]);
        $this->backdate($greenfield, createdDaysAgo: 90);

        $this->logActivity($greenfield, $sam, ActivityType::Meeting, 'Site visit and requirements walkthrough');
        $greenfield->transitionTo(LeadStatus::Contacted, $sam, 'First outbound activity logged.');
        $greenfield->transitionTo(LeadStatus::Qualified, $sam, 'Requirements signed off by HR.');
        $this->backdate($greenfield, activityDaysAgo: 65);
    }

    private function logActivity(
        Lead $lead,
        User $owner,
        ActivityType $type,
        string $subject,
        ?string $body = null,
    ): void {
        $lead->activities()->create([
            'type' => $type,
            'subject' => $subject,
            'body' => $body,
            'status' => ActivityStatus::Done,
            'occurred_at' => now(),
            'owner_id' => $owner->id,
        ]);
    }

    /**
     * Back-date a seeded lead (and its activities) so the aging buckets are populated.
     * Uses forceFill because the timestamps are not mass-assignable.
     */
    private function backdate(
        Lead $lead,
        ?int $createdDaysAgo = null,
        ?int $activityDaysAgo = null,
    ): void {
        $attributes = [];

        if ($createdDaysAgo !== null) {
            $attributes['created_at'] = Carbon::now()->subDays($createdDaysAgo);
        }

        if ($activityDaysAgo !== null) {
            $attributes['last_activity_at'] = Carbon::now()->subDays($activityDaysAgo);
            $attributes['status_changed_at'] = Carbon::now()->subDays($activityDaysAgo);
        }

        if ($attributes === []) {
            return;
        }

        $lead->forceFill($attributes)->save();

        if ($activityDaysAgo !== null) {
            $lead->activities()->update([
                'occurred_at' => Carbon::now()->subDays($activityDaysAgo),
                'created_at' => Carbon::now()->subDays($activityDaysAgo),
            ]);
        }
    }
}
