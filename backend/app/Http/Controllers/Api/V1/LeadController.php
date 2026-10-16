<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Lead::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);

        $leads = Lead::query()
            ->with('owner')
            ->visibleTo($request->user())
            ->search($request->string('search')->toString() ?: null)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
            ->when($filters['owner'] ?? null, function ($query, $owner) use ($request) {
                return match ($owner) {
                    'me' => $query->where('owner_id', $request->user()->id),
                    'unassigned' => $query->whereNull('owner_id'),
                    default => $query->where('owner_id', $owner),
                };
            })
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        return LeadResource::collection($leads);
    }

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $data = $request->validated();

        $duplicates = $this->findDuplicates($data);

        $lead = DB::transaction(function () use ($data, $request): Lead {
            if ($request->user()->role === Role::Salesrep) {
                $data['owner_id'] = $request->user()->id;
            }

            return Lead::create($data);
        });

        return (new LeadResource($lead->load('owner')))
            ->additional(['duplicates' => $duplicates])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Lead $lead): LeadResource
    {
        $this->authorize('view', $lead);

        return new LeadResource($lead->load('owner'));
    }

    public function destroy(Lead $lead): Response
    {
        $this->authorize('delete', $lead);

        $lead->delete();

        return response()->noContent();
    }

    public function restore(string $id): LeadResource
    {
        $lead = Lead::withTrashed()->findOrFail($id);

        $this->authorize('restore', $lead);

        $lead->restore();

        return new LeadResource($lead->load('owner'));
    }

    /**
     * Advisory duplicate detection (submodule 1). Hard blocking is Phase 2 (ADR-0006).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function findDuplicates(array $data): array
    {
        $company = mb_strtolower(trim((string) $data['company_name']));
        $email = $data['contact_email'] ?? null;
        $phone = $data['contact_phone'] ?? null;

        return Lead::query()
            ->where(function ($query) use ($company, $email, $phone): void {
                $query->whereRaw('LOWER(company_name) = ?', [$company]);

                if ($email) {
                    $query->orWhere('contact_email', $email);
                }

                if ($phone) {
                    $query->orWhere('contact_phone', $phone);
                }
            })
            ->limit(10)
            ->get(['id', 'reference', 'company_name'])
            ->map(fn (Lead $lead): array => [
                'id' => $lead->id,
                'reference' => $lead->reference,
                'company_name' => $lead->company_name,
            ])
            ->all();
    }
}
