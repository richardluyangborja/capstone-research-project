<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContractRequest;
use App\Http\Resources\ContractResource;
use App\Models\Company;
use App\Models\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Contracts of one company — the Contracts tab on `/accounts/:id` (spec 09). Creation
 * lives here; the flat routes handle a single contract.
 */
class CompanyContractController extends Controller
{
    public function index(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);

        $contracts = $company->contracts()
            ->with(['owner:id,name', 'renewalOf:id,reference,end_date'])
            ->visibleTo($request->user())
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('status', explode(',', (string) $status)))
            ->when($filters['expiring'] ?? null, fn ($query) => $query
                ->where('status', ContractStatus::Signed->value)
                ->whereNotNull('end_date')
                ->whereDate('end_date', '<=', now()->addDays((int) config('crm.contracts.expiring_soon_days'))))
            ->orderByDesc('created_at')
            ->get();

        return ContractResource::collection($contracts)->additional([
            'meta' => [
                'total' => $contracts->count(),
                'active' => $contracts->filter(fn (Contract $contract): bool => $contract->isActive())->count(),
            ],
        ]);
    }

    public function store(StoreContractRequest $request, Company $company): JsonResponse
    {
        $contract = DB::transaction(function () use ($request, $company): Contract {
            $data = $request->validated();

            /** @var UploadedFile $file */
            $file = $request->file('file');
            $path = $file->store('contracts', config('crm.contracts.disk'));

            /** @var Contract $contract */
            $contract = $company->contracts()->create([
                'opportunity_id' => $data['opportunity_id'] ?? null,
                'renewal_of_contract_id' => $data['renewal_of_contract_id'] ?? null,
                'title' => $data['title'],
                'status' => $data['status'] ?? ContractStatus::Draft,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'value' => $data['value'] ?? null,
                'currency' => $data['currency'] ?? 'PHP',
                'payment_terms' => $data['payment_terms'] ?? null,
                'po_reference' => $data['po_reference'] ?? null,
                'auto_renew' => (bool) ($data['auto_renew'] ?? false),
                'notice_days' => $data['notice_days'] ?? null,
                'signatory_name' => $data['signatory_name'] ?? null,
                'signatory_position' => $data['signatory_position'] ?? null,
                'terms' => $data['terms'] ?? null,
                'notes' => $data['notes'] ?? null,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'file_mime' => $file->getClientMimeType(),
                // Contracts inherit the account's owner so account and contract scoping
                // always agree; the uploader is recorded on the timeline instead.
                'owner_id' => $company->owner_id,
            ]);

            $company->activities()->create([
                'type' => ActivityType::System,
                'subject' => sprintf('Contract %s uploaded', $contract->reference),
                'body' => sprintf('%s (%s).', $contract->title, $contract->status->value),
                'status' => ActivityStatus::Done,
                'owner_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return $contract;
        });

        return (new ContractResource($contract->load(['company', 'owner:id,name'])))
            ->response()
            ->setStatusCode(201);
    }
}
