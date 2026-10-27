<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\ContractStatus;
use App\Events\ContractSigned;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateContractRequest;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Flat contract routes (spec 09): detail, terms update, the mark-signed action that
 * activates the client, the document download and retirement. Creation lives under its
 * company (`CompanyContractController`).
 */
class ContractController extends Controller
{
    public function show(Contract $contract): ContractResource
    {
        $this->authorize('view', $contract);

        return new ContractResource($contract->load(['company', 'opportunity', 'renewalOf', 'owner:id,name']));
    }

    public function update(UpdateContractRequest $request, Contract $contract): ContractResource
    {
        DB::transaction(function () use ($request, $contract): void {
            $contract->update($request->validated());
            $this->recordHeaderChange($contract, $request->user());
        });

        return new ContractResource($contract->load(['company', 'owner:id,name']));
    }

    /**
     * The only path to `signed` (spec 09 §1). It advances the client and fires the mocked
     * Service handoff — through `ContractSigned`, which runs after this transaction so a
     * failed integration can never roll the signed contract back.
     */
    public function markSigned(Request $request, Contract $contract): ContractResource
    {
        $this->authorize('markSigned', $contract);

        if (! $contract->status->canTransitionTo(ContractStatus::Signed)) {
            throw ValidationException::withMessages([
                'status' => [sprintf('A %s contract cannot be signed.', $contract->status->value)],
            ]);
        }

        DB::transaction(fn () => $contract->markSigned());

        ContractSigned::dispatch($contract, $contract->company, $request->user());

        return new ContractResource($contract->fresh()->load(['company', 'owner:id,name']));
    }

    /**
     * The stored document, streamed through an authorized endpoint — the private path is
     * never exposed to the client (spec 09 §API).
     */
    public function download(Contract $contract): StreamedResponse
    {
        $this->authorize('view', $contract);

        if (! $contract->hasFile()) {
            abort(404, 'This contract has no document attached.');
        }

        $disk = Storage::disk(config('crm.contracts.disk'));

        if (! $disk->exists($contract->file_path)) {
            abort(404, 'The contract document is missing from storage.');
        }

        return $disk->download(
            $contract->file_path,
            $contract->file_name ?? basename($contract->file_path),
        );
    }

    public function destroy(Contract $contract): ContractResource
    {
        $this->authorize('delete', $contract);

        $contract->delete();

        return new ContractResource($contract->load(['company', 'owner:id,name']));
    }

    public function restore(string $id): ContractResource
    {
        $contract = Contract::withTrashed()->findOrFail($id);

        $this->authorize('restore', $contract);

        $contract->restore();

        return new ContractResource($contract->load(['company', 'owner:id,name']));
    }

    /**
     * A header edit is commercial work on the account, so it lands on the account timeline as a
     * `system` activity naming what changed (spec 16 §Contract commercial model). A no-op PATCH
     * writes nothing.
     */
    private function recordHeaderChange(Contract $contract, ?User $actor): void
    {
        $labels = [
            'title' => 'title',
            'status' => 'status',
            'start_date' => 'start date',
            'end_date' => 'end date',
            'value' => 'value',
            'currency' => 'currency',
            'payment_terms' => 'payment terms',
            'po_reference' => 'PO reference',
            'auto_renew' => 'auto-renew',
            'notice_days' => 'notice window',
            'signatory_name' => 'signatory',
            'signatory_position' => 'signatory position',
            'terms' => 'terms',
            'notes' => 'notes',
        ];

        $changed = [];

        foreach (array_keys($contract->getChanges()) as $field) {
            if ($field === 'updated_at') {
                continue;
            }

            $changed[] = $labels[$field] ?? str_replace('_', ' ', $field);
        }

        if ($changed === []) {
            return;
        }

        $contract->company->activities()->create([
            'type' => ActivityType::System,
            'subject' => sprintf('Contract %s updated', $contract->reference),
            'body' => ucfirst(implode(', ', $changed)).' changed.',
            'status' => ActivityStatus::Done,
            'owner_id' => $actor?->id ?? $contract->owner_id,
            'occurred_at' => now(),
        ]);
    }
}
