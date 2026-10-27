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
                'value' => 1250000.00,
                'payment_terms' => 'Net 30',
                'po_reference' => 'PO-BPO-2026-011',
                'auto_renew' => true,
                'notice_days' => 90,
                'signatory_name' => 'Miguel Santos',
                'signatory_position' => 'Country Manager',
                'terms' => 'Net 30, 90-day ramp-down notice, monthly service review.',
                'ageDays' => 30,
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
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contract(Company $company, User $owner, array $attributes): Contract
    {
        $ageDays = $attributes['ageDays'] ?? 0;
        unset($attributes['ageDays']);

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
