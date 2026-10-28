<?php

namespace Database\Seeders;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\ContractStatus;
use App\Enums\HandoffStatus;
use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Fixed demo contracts (no faker — seeded for the proposal demo, see system-tech.md).
 *
 * Covers the shapes the Contracts tab and lifecycle stepper can show: a live engagement
 * with its earlier (expired) agreement it renews, a signed contract whose Service handoff
 * failed (the retry banner), one out for signature, a terminated account and a draft.
 *
 * A placeholder document is written to the private disk so the download endpoint works in
 * the demo (the API requires a file, so the seed cannot leave the column empty).
 */
class DemoContractSeeder extends Seeder
{
    /** A valid 1x1 PNG standing in for a scanned signed contract. */
    private const PLACEHOLDER_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function run(): void
    {
        $sam = User::where('email', 'salesrep@primepower.test')->firstOrFail();
        $liza = User::where('email', 'salesrep2@primepower.test')->firstOrFail();

        // Harbor Logistics — the active client: an expired agreement replaced by the live one.
        $harbor = Company::where('name', 'Harbor Logistics')->first();

        if ($harbor !== null) {
            $original = $this->contract($harbor, $liza, [
                'title' => 'Forklift operator pooling agreement (2025)',
                'status' => ContractStatus::Expired,
                'start_date' => Carbon::now()->subMonths(18),
                'end_date' => Carbon::now()->subMonths(6),
                'signed_at' => Carbon::now()->subMonths(18),
                'value' => 1980000.00,
                'payment_terms' => 'Net 30',
                'po_reference' => 'PO-HL-2025-018',
                'auto_renew' => false,
                'notice_days' => 60,
                'signatory_name' => 'Elena Cruz',
                'signatory_position' => 'VP, Operations',
                'terms' => 'Net 30, 60-day notice on volume changes.',
                'ageDays' => 200,
                'rates' => [
                    ['role_title' => 'Warehouse associate', 'headcount' => 5, 'billing_unit' => 'hourly', 'bill_rate' => 105, 'pay_rate' => 80, 'units_per_period' => 176, 'site' => 'Manila South Harbor office'],
                    ['role_title' => 'Forklift operator', 'headcount' => 2, 'billing_unit' => 'hourly', 'bill_rate' => 130, 'pay_rate' => 95, 'units_per_period' => 176, 'site' => 'Manila South Harbor office'],
                    ['role_title' => 'Site supervisor', 'headcount' => 1, 'billing_unit' => 'monthly', 'bill_rate' => 25000, 'pay_rate' => 18000, 'units_per_period' => 1, 'site' => 'Manila South Harbor office'],
                ],
            ]);

            $this->contract($harbor, $liza, [
                'title' => 'Forklift operator pooling agreement (2026 renewal)',
                'status' => ContractStatus::Signed,
                'renewal_of_contract_id' => $original->id,
                'start_date' => Carbon::now()->subMonths(6),
                'end_date' => Carbon::now()->addDays(24),
                'signed_at' => Carbon::now()->subMonths(6),
                'value' => 2400000.00,
                'payment_terms' => 'Net 30',
                'po_reference' => 'PO-HL-2026-004',
                'auto_renew' => true,
                'notice_days' => 60,
                'signatory_name' => 'Elena Cruz',
                'signatory_position' => 'VP, Operations',
                'terms' => 'Net 30, 60-day notice on volume changes. Annual escalation capped at 5%.',
                'ageDays' => 180,
                'rates' => [
                    ['role_title' => 'Warehouse associate', 'headcount' => 6, 'billing_unit' => 'hourly', 'bill_rate' => 110, 'pay_rate' => 85, 'units_per_period' => 176, 'site' => 'Manila South Harbor office'],
                    ['role_title' => 'Forklift operator', 'headcount' => 2, 'billing_unit' => 'hourly', 'bill_rate' => 140, 'pay_rate' => 105, 'units_per_period' => 176, 'site' => 'Manila South Harbor office'],
                    ['role_title' => 'Site supervisor', 'headcount' => 1, 'billing_unit' => 'monthly', 'bill_rate' => 30000, 'pay_rate' => 22000, 'units_per_period' => 1, 'site' => 'Manila South Harbor office'],
                ],
            ]);

            $harbor->forceFill([
                'handoff_status' => HandoffStatus::Sent,
                'handoff_reference' => 'SVC-CON-'.$harbor->contracts()->orderByDesc('created_at')->value('reference'),
                'handoff_error' => null,
                'handoff_requested_at' => Carbon::now()->subMonths(6),
            ])->save();
        }

        // BPO Solutions PH — signed, but Service never acknowledged: the retry banner.
        $bpo = Company::where('name', 'BPO Solutions PH')->first();

        if ($bpo !== null) {
            $this->contract($bpo, $sam, [
                'title' => 'Contact centre staffing agreement — 40 seats',
                'status' => ContractStatus::Signed,
                'start_date' => Carbon::now()->subMonth(),
                'end_date' => Carbon::now()->addMonths(11),
                'signed_at' => Carbon::now()->subMonth(),
                'value' => 14760000.00,
                'payment_terms' => 'Net 30',
                'po_reference' => 'PO-BPO-2026-011',
                'auto_renew' => true,
                'notice_days' => 90,
                'signatory_name' => 'Miguel Santos',
                'signatory_position' => 'Country Manager',
                'terms' => 'Net 30, 90-day ramp-down notice, monthly service review.',
                'ageDays' => 30,
                'rates' => [
                    ['role_title' => 'Customer service associate', 'headcount' => 35, 'billing_unit' => 'monthly', 'bill_rate' => 28000, 'pay_rate' => 22000, 'units_per_period' => 1, 'site' => 'Ortigas delivery center'],
                    ['role_title' => 'Team lead', 'headcount' => 4, 'billing_unit' => 'monthly', 'bill_rate' => 45000, 'pay_rate' => 35000, 'units_per_period' => 1, 'site' => 'Ortigas delivery center'],
                    ['role_title' => 'Account manager', 'headcount' => 1, 'billing_unit' => 'monthly', 'bill_rate' => 70000, 'pay_rate' => 55000, 'units_per_period' => 1, 'site' => 'Ortigas delivery center'],
                ],
            ]);

            $bpo->forceFill([
                'client_since' => Carbon::now()->subMonth()->toDateString(),
                'handoff_status' => HandoffStatus::Failed,
                'handoff_reference' => null,
                'handoff_error' => 'The Service desk did not acknowledge the handoff (mocked failure).',
                'handoff_requested_at' => Carbon::now()->subMonth(),
            ])->save();
        }

        // Sunrise Foods Inc — prospect with an agreement out for signature.
        $sunrise = Company::where('name', 'Sunrise Foods Inc')->first();

        if ($sunrise !== null) {
            $this->contract($sunrise, $sam, [
                'title' => 'Warehouse staffing agreement (proposed)',
                'status' => ContractStatus::Sent,
                'start_date' => Carbon::now()->addWeeks(2),
                'end_date' => Carbon::now()->addYear(),
                'value' => 450000.00,
                'payment_terms' => 'Net 15',
                'po_reference' => 'PO-SF-2026-002',
                'auto_renew' => false,
                'notice_days' => 30,
                'signatory_name' => 'Ramon Villanueva',
                'signatory_position' => 'Plant HR Manager',
                'terms' => 'Net 15, seasonal headcount billed monthly.',
                'ageDays' => 6,
                'rates' => [
                    ['role_title' => 'Warehouse associate', 'headcount' => 2, 'billing_unit' => 'hourly', 'bill_rate' => 110, 'pay_rate' => 85, 'units_per_period' => 176, 'site' => 'Head office'],
                    ['role_title' => 'Recruitment placement', 'headcount' => 2, 'billing_unit' => 'placement', 'bill_rate' => 15000, 'pay_rate' => 4000, 'units_per_period' => 1, 'site' => null, 'notes' => 'One-time placement fee per successful hire.'],
                ],
            ]);
        }

        // Greenfield Hotels — churned after the housekeeping engagement was terminated.
        $greenfield = Company::where('name', 'Greenfield Hotels')->first();

        if ($greenfield !== null) {
            $this->contract($greenfield, $sam, [
                'title' => 'Housekeeping crew agreement',
                'status' => ContractStatus::Terminated,
                'start_date' => Carbon::now()->subYears(2),
                'end_date' => Carbon::now()->subMonths(3),
                'signed_at' => Carbon::now()->subYears(2),
                'value' => 980000.00,
                'payment_terms' => 'Net 30',
                'po_reference' => 'PO-GH-2024-021',
                'auto_renew' => false,
                'notice_days' => 30,
                'signatory_name' => 'Cecilia Tan',
                'signatory_position' => 'General Manager',
                'terms' => 'Net 30. Terminated by mutual agreement.',
                'ageDays' => 90,
                'rates' => [
                    ['role_title' => 'Housekeeper', 'headcount' => 5, 'billing_unit' => 'hourly', 'bill_rate' => 80, 'pay_rate' => 60, 'units_per_period' => 176, 'site' => 'Makati property'],
                    ['role_title' => 'Housekeeping supervisor', 'headcount' => 1, 'billing_unit' => 'monthly', 'bill_rate' => 12000, 'pay_rate' => 9500, 'units_per_period' => 1, 'site' => 'Makati property'],
                ],
            ]);
        }

        // Greenfield Hotels Cebu — a branch still working up its first agreement.
        $cebu = Company::where('name', 'Greenfield Hotels Cebu')->first();

        if ($cebu !== null) {
            $this->contract($cebu, $liza, [
                'title' => 'Housekeeping crew agreement (draft)',
                'status' => ContractStatus::Draft,
                'start_date' => Carbon::now()->addMonth(),
                'end_date' => Carbon::now()->addMonths(13),
                'value' => 620000.00,
                'payment_terms' => 'Net 30',
                'po_reference' => null,
                'auto_renew' => true,
                'notice_days' => 30,
                'signatory_name' => 'Cecilia Tan',
                'signatory_position' => 'General Manager',
                'terms' => "Draft — awaiting the Cebu property's headcount confirmation.",
                'ageDays' => 3,
                'rates' => [
                    ['role_title' => 'Housekeeper', 'headcount' => 3, 'billing_unit' => 'hourly', 'bill_rate' => 88, 'pay_rate' => 65, 'units_per_period' => 176, 'site' => 'Cebu resort'],
                    // Awaiting the client's confirmation — the margin stays null until it lands.
                    ['role_title' => 'Site supervisor', 'headcount' => 1, 'billing_unit' => 'monthly', 'bill_rate' => 10000, 'pay_rate' => null, 'units_per_period' => 1, 'site' => 'Cebu resort'],
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contract(Company $company, User $owner, array $attributes): Contract
    {
        $ageDays = $attributes['ageDays'] ?? 0;
        $rates = $attributes['rates'] ?? [];
        unset($attributes['ageDays'], $attributes['rates']);

        /** @var Contract $contract */
        $contract = $company->contracts()->create([
            'opportunity_id' => $company->opportunities()->orderByDesc('created_at')->value('id'),
            'title' => $attributes['title'],
            'status' => $attributes['status'],
            'renewal_of_contract_id' => $attributes['renewal_of_contract_id'] ?? null,
            'start_date' => $attributes['start_date']->toDateString(),
            'end_date' => $attributes['end_date']->toDateString(),
            'signed_at' => $attributes['signed_at'] ?? null,
            'value' => $attributes['value'],
            'currency' => 'PHP',
            'payment_terms' => $attributes['payment_terms'],
            'po_reference' => $attributes['po_reference'],
            'auto_renew' => $attributes['auto_renew'],
            'notice_days' => $attributes['notice_days'],
            'signatory_name' => $attributes['signatory_name'],
            'signatory_position' => $attributes['signatory_position'],
            'terms' => $attributes['terms'],
            'owner_id' => $company->owner_id,
        ]);

        $this->attachPlaceholder($contract);
        $this->createRateCards($contract, $company, $rates);

        $contract->forceFill([
            'created_at' => Carbon::now()->subDays($ageDays),
            'updated_at' => Carbon::now()->subDays(max($ageDays - 1, 0)),
        ])->save();

        $company->activities()->create([
            'type' => ActivityType::System,
            'subject' => sprintf('Contract %s %s', $contract->reference, $attributes['status']->value),
            'body' => $contract->title,
            'status' => ActivityStatus::Done,
            'owner_id' => $owner->id,
            'occurred_at' => Carbon::now()->subDays($ageDays),
        ]);

        return $contract;
    }

    /**
     * The structured commercial lines behind the agreement (module 01 refresh S7). Each
     * seeded contract has a realistic shape so the Commercial tab demos every unit.
     *
     * @param  array<int, array<string, mixed>>  $rates
     */
    private function createRateCards(Contract $contract, Company $company, array $rates): void
    {
        foreach ($rates as $index => $rate) {
            $siteLabel = $rate['site'] ?? null;
            unset($rate['site']);

            $contract->rateCards()->create([
                ...$rate,
                'site_id' => $siteLabel
                    ? $company->sites()->where('label', $siteLabel)->value('id')
                    : null,
                'position' => $index + 1,
            ]);
        }
    }

    private function attachPlaceholder(Contract $contract): void
    {
        $disk = config('crm.contracts.disk');
        $path = 'contracts/'.$contract->id.'.png';

        Storage::disk($disk)->put($path, base64_decode(self::PLACEHOLDER_PNG, true));

        $contract->forceFill([
            'file_path' => $path,
            'file_name' => $contract->reference.'-signed.png',
            'file_size' => strlen(base64_decode(self::PLACEHOLDER_PNG, true)),
            'file_mime' => 'image/png',
        ])->save();
    }
}
