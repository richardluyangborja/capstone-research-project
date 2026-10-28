<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReplaceContractRateCardsRequest;
use App\Http\Resources\ContractRateCardResource;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The structured commercial lines on a contract (module 01 refresh S7, spec 16 §Contract
 * commercial model, ADR-0024). The set is replaced wholesale and every change lands on both
 * timelines; writing is manager+.
 */
class ContractRateCardController extends Controller
{
    public function index(Request $request, Contract $contract): AnonymousResourceCollection
    {
        $this->authorize('view', $contract);

        $cards = $contract->rateCards()->with('site:id,label')->get();

        return ContractRateCardResource::collection($cards);
    }

    public function update(
        ReplaceContractRateCardsRequest $request,
        Contract $contract,
    ): ContractResource {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $request->validated()['rate_cards'];

        DB::transaction(function () use ($contract, $request, $rows): void {
            $contract->rateCards()->delete();

            foreach ($rows as $index => $row) {
                $contract->rateCards()->create([
                    'site_id' => $row['site_id'] ?? null,
                    'role_title' => $row['role_title'],
                    'headcount' => $row['headcount'],
                    'billing_unit' => $row['billing_unit'],
                    'bill_rate' => $row['bill_rate'],
                    'pay_rate' => $row['pay_rate'] ?? null,
                    'units_per_period' => $row['units_per_period'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'position' => $index + 1,
                ]);
            }

            $contract->load('rateCards');
            $summary = $contract->commercialSummary();
            $body = sprintf(
                '%d %s replaced · client monthly PHP %s.',
                count($rows),
                Str::plural('line', count($rows)),
                number_format($summary['client_monthly_value'], 2),
            );

            $this->recordChange($contract, $request, $body);
        });

        return new ContractResource(
            $contract->fresh()->load(['company', 'owner:id,name', 'rateCards.site']),
        );
    }

    /**
     * Rate-card corrections are visible on the contract and the account (spec 16
     * §Visibility and editing). The body carries client-facing value only.
     */
    private function recordChange(
        Contract $contract,
        ReplaceContractRateCardsRequest $request,
        string $body,
    ): void {
        $attributes = [
            'type' => ActivityType::System,
            'subject' => sprintf('Contract %s rate cards updated', $contract->reference),
            'body' => $body,
            'status' => ActivityStatus::Done,
            'owner_id' => $request->user()?->id ?? $contract->owner_id,
            'occurred_at' => now(),
        ];

        $contract->activities()->create($attributes);
        $contract->company->activities()->create($attributes);
    }
}
