<?php

namespace Database\Seeders;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\CompanySize;
use App\Enums\CompanyStatus;
use App\Enums\CompanyTier;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fixed demo accounts (no faker — seeded for the proposal demo, see system-tech.md).
 *
 * Covers the states the company UI can show: a prospect, an onboarding client, an active
 * client, a churned client, a multi-site company and a branch with a parent.
 */
class DemoCompanySeeder extends Seeder
{
    public function run(): void
    {
        $sam = User::where('email', 'salesrep@primepower.test')->firstOrFail();
        $liza = User::where('email', 'salesrep2@primepower.test')->firstOrFail();

        $sunriseLead = Lead::where('company_name', 'Sunrise Foods Inc')->first();
        $bpoLead = Lead::where('company_name', 'BPO Solutions PH')->first();
        $harborLead = Lead::where('company_name', 'Harbor Logistics')->first();

        $sunrise = Company::create([
            'name' => 'Sunrise Foods Inc',
            'trade_name' => 'Sunrise Foods',
            'industry' => 'Food Manufacturing',
            'size' => CompanySize::Medium,
            'website' => 'https://sunrisefoods.ph',
            'description' => 'Bakery and packaged-goods manufacturer supplying Metro Manila groceries.',
            'status' => CompanyStatus::Prospect,
            'tier' => CompanyTier::B,
            'source_lead_id' => $sunriseLead?->id,
            'owner_id' => $sam->id,
            'tags' => ['walk-in', 'warehouse'],
        ]);
        $this->backdate($sunrise, createdDaysAgo: 2);

        $this->addSite($sunrise, [
            'label' => 'Head office',
            'street_address' => '12 Del Monte Avenue',
            'barangay' => 'Barangay Sienna',
            'city_municipality' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
            'postal_code' => '1103',
            'is_primary' => true,
        ]);

        $this->addSite($sunrise, [
            'label' => 'Commissary',
            'street_address' => '88 Balintawak Street',
            'barangay' => 'Barangay Balingasa',
            'city_municipality' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
            'postal_code' => '1106',
        ]);

        $this->logActivity($sunrise, $sam, ActivityType::Note, 'Intake call scheduled', 'Confirmed the holiday peak staffing window.');

        $bpo = Company::create([
            'name' => 'BPO Solutions PH',
            'trade_name' => 'BPO Solutions',
            'industry' => 'Business Process Outsourcing',
            'size' => CompanySize::Large,
            'website' => 'https://bposolutions.ph',
            'status' => CompanyStatus::Onboarding,
            'tier' => CompanyTier::A,
            'source_lead_id' => $bpoLead?->id,
            'owner_id' => $sam->id,
        ]);
        $this->backdate($bpo, createdDaysAgo: 8);

        $this->addSite($bpo, [
            'label' => 'Ortigas delivery center',
            'street_address' => 'Level 8, One San Miguel Avenue',
            'barangay' => 'Barangay San Antonio',
            'city_municipality' => 'Pasig',
            'province' => 'Metro Manila',
            'region' => 'NCR',
            'postal_code' => '1605',
            'is_primary' => true,
        ]);

        $this->addSite($bpo, [
            'label' => 'Cebu recruitment hub',
            'street_address' => '3rd Floor, Cebu IT Park Tower',
            'barangay' => 'Barangay Apas',
            'city_municipality' => 'Cebu City',
            'province' => 'Cebu',
            'region' => 'Region VII',
            'postal_code' => '6000',
        ]);

        $this->logActivity($bpo, $sam, ActivityType::Meeting, 'Onboarding kickoff', 'Agreed the CSR ramp-up schedule for the Ortigas site.');

        $harbor = Company::create([
            'name' => 'Harbor Logistics',
            'trade_name' => 'Harbor Logistics',
            'industry' => 'Logistics',
            'size' => CompanySize::Medium,
            'status' => CompanyStatus::Active,
            'tier' => CompanyTier::A,
            'client_since' => now()->subMonths(6)->toDateString(),
            'source_lead_id' => $harborLead?->id,
            'owner_id' => $liza->id,
        ]);
        $this->backdate($harbor, createdDaysAgo: 180);

        $this->addSite($harbor, [
            'label' => 'Manila South Harbor office',
            'street_address' => 'Pier 4, Port Area',
            'barangay' => 'Barangay 653',
            'city_municipality' => 'Manila',
            'province' => 'Metro Manila',
            'region' => 'NCR',
            'postal_code' => '1012',
            'is_primary' => true,
        ]);

        $this->logActivity($harbor, $liza, ActivityType::Email, 'Signed rate agreement', 'Countersigned the 2026 forklift operator rate card.');
        $this->logActivity($harbor, $liza, ActivityType::Call, 'Quarterly check-in', 'Happy with the assigned operators; considering a second shift.');

        $greenfield = Company::create([
            'name' => 'Greenfield Hotels',
            'trade_name' => 'Greenfield Hotels & Resorts',
            'industry' => 'Hospitality',
            'size' => CompanySize::Large,
            'status' => CompanyStatus::Churned,
            'tier' => CompanyTier::C,
            'client_since' => now()->subYears(3)->toDateString(),
            'owner_id' => $sam->id,
        ]);
        $this->backdate($greenfield, createdDaysAgo: 90);

        $this->addSite($greenfield, [
            'label' => 'Makati property',
            'street_address' => '120 Ayala Avenue',
            'barangay' => 'Barangay Bel-Air',
            'city_municipality' => 'Makati',
            'province' => 'Metro Manila',
            'region' => 'NCR',
            'postal_code' => '1226',
            'is_primary' => true,
        ]);

        $this->logActivity($greenfield, $sam, ActivityType::Note, 'Account churned', 'Volume dropped after the housekeeping contract ended.');

        // A branch: its own sites and owner, inheriting nothing from the head office (spec 06 §3).
        $greenfieldCebu = Company::create([
            'name' => 'Greenfield Hotels Cebu',
            'trade_name' => 'Greenfield Cebu',
            'industry' => 'Hospitality',
            'size' => CompanySize::Medium,
            'status' => CompanyStatus::Onboarding,
            'tier' => CompanyTier::B,
            'parent_company_id' => $greenfield->id,
            'owner_id' => $liza->id,
        ]);
        $this->backdate($greenfieldCebu, createdDaysAgo: 30);

        $this->addSite($greenfieldCebu, [
            'label' => 'Cebu resort',
            'street_address' => '8 Punta Engaño Road',
            'barangay' => 'Barangay Punta Engaño',
            'city_municipality' => 'Lapu-Lapu City',
            'province' => 'Cebu',
            'region' => 'Region VII',
            'postal_code' => '6015',
            'is_primary' => true,
        ]);

        $this->logActivity($greenfieldCebu, $liza, ActivityType::Call, 'Branch staffing enquiry', 'New Cebu property needs 20 housekeeping staff.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function addSite(Company $company, array $attributes): void
    {
        $company->sites()->create($attributes);
    }

    private function logActivity(
        Company $company,
        User $owner,
        ActivityType $type,
        string $subject,
        ?string $body = null,
    ): void {
        $company->activities()->create([
            'type' => $type,
            'subject' => $subject,
            'body' => $body,
            'status' => ActivityStatus::Done,
            'occurred_at' => now(),
            'owner_id' => $owner->id,
        ]);
    }

    /**
     * Back-date a seeded company so the list does not look freshly created.
     * Uses forceFill because the timestamps are not mass-assignable.
     */
    private function backdate(Company $company, int $createdDaysAgo): void
    {
        $company->forceFill(['created_at' => Carbon::now()->subDays($createdDaysAgo)])->save();
    }
}
