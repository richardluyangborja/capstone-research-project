<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only contract projection routes (ADR-0027).
 */
class ContractController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Contract::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);
        $user = $request->user();

        $contracts = Contract::query()
            ->with(['company', 'owner:id,name', 'renewalOf:id,reference,title,status,start_date,end_date', 'rateCards.site'])
            ->visibleTo($user)
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = $request->string('search')->toString();
                $like = '%'.mb_strtolower($term).'%';
                $query->where(function ($q) use ($like): void {
                    $q->whereRaw('LOWER(title) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(reference) LIKE ?', [$like])
                        ->orWhereHas('company', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$like]));
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('status', explode(',', (string) $status)))
            ->when(filter_var($filters['expiring'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($query) => $query->signedExpiringSoon())
            ->when($filters['owner'] ?? $filters['owner_id'] ?? null, function ($query, $owner) use ($user) {
                return match ($owner) {
                    'me' => $query->where('owner_id', $user->id),
                    'team' => $query->whereHas('owner', fn ($q) => $q->where('team_id', $user->team_id)),
                    default => $query->where('owner_id', $owner),
                };
            })
            ->when($filters['company'] ?? $filters['company_id'] ?? null, fn ($query, $company) => $query->where('company_id', $company))
            ->when(isset($filters['renewal']), function ($query) use ($filters): void {
                $isRenewal = filter_var($filters['renewal'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isRenewal === true) {
                    $query->whereNotNull('renewal_of_contract_id');
                } elseif ($isRenewal === false) {
                    $query->whereNull('renewal_of_contract_id');
                }
            })
            ->orderByDesc('created_at')
            ->paginate(max(1, min((int) $request->integer('per_page', 25), 100)))
            ->appends($request->query());

        $base = fn () => Contract::query()->visibleTo($user);

        $pagination = $contracts->toArray();
        $paginationMeta = [
            'current_page' => $pagination['current_page'],
            'from' => $pagination['from'],
            'last_page' => $pagination['last_page'],
            'per_page' => $pagination['per_page'],
            'to' => $pagination['to'],
            'total' => $pagination['total'],
        ];

        return ContractResource::collection($contracts)->additional([
            'meta' => [
                ...$paginationMeta,
                'source' => 'Contract Management (mocked, read-only)',
                'read_only' => true,
                'as_of' => now()->toIso8601String(),
            ],
            'summary' => [
                'all' => $base()->count(),
                'active' => $base()->where('status', ContractStatus::Signed)->count(),
                'draft' => $base()->where('status', ContractStatus::Draft)->count(),
                'sent' => $base()->where('status', ContractStatus::Sent)->count(),
                'expiring' => $base()->signedExpiringSoon()->count(),
                'renewals' => $base()->whereNotNull('renewal_of_contract_id')->count(),
            ],
        ]);
    }

    public function show(Contract $contract): ContractResource
    {
        $this->authorize('view', $contract);

        return new ContractResource($contract->load(ContractResource::detailRelations()));
    }
}
