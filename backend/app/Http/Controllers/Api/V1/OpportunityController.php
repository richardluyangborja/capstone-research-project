<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OpportunityStage;
use App\Events\OpportunityCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOpportunityRequest;
use App\Http\Requests\UpdateOpportunityRequest;
use App\Http\Resources\OpportunityResource;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The deal book (module 02): list, create, edit, delete and restore. A deal is created
 * directly on an account or by lead→client conversion (spec 08); stage moves have their own
 * controller so the pipeline rules and the timeline always apply.
 */
class OpportunityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Opportunity::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);
        $user = $request->user();

        $opportunities = Opportunity::query()
            ->with(['owner:id,name', 'company'])
            ->visibleTo($user)
            ->search($request->string('search')->toString() ?: null)
            ->when($filters['stage'] ?? null, fn ($query, $stage) => $query->whereIn('stage', explode(',', (string) $stage)))
            ->when(filter_var($filters['open'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($query) => $query->open())
            ->when($filters['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
            ->when($filters['close_window'] ?? null, fn ($query, $window) => $query->closeWindow((string) $window))
            ->when($filters['company'] ?? null, fn ($query, $company) => $query->where('company_id', $company))
            ->when($filters['owner'] ?? null, function ($query, $owner) use ($user) {
                return match ($owner) {
                    'me' => $query->where('owner_id', $user->id),
                    'team' => $query->whereHas('owner', fn ($q) => $q->where('team_id', $user->team_id)),
                    default => $query->where('owner_id', $owner),
                };
            })
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        $base = fn () => Opportunity::query()->visibleTo($user);

        return OpportunityResource::collection($opportunities)->additional([
            'summary' => [
                'all' => $base()->count(),
                'open' => $base()->open()->count(),
                'won' => $base()->where('stage', OpportunityStage::Won->value)->count(),
                'lost' => $base()->where('stage', OpportunityStage::Lost->value)->count(),
            ],
        ]);
    }

    public function store(StoreOpportunityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        /** @var Company $company */
        $company = Company::query()->findOrFail($data['company_id']);

        // Never open a deal on a company outside the caller's scope (module 02 §3).
        if ($user->cannot('view', $company)) {
            throw ValidationException::withMessages([
                'company_id' => ['That company is not in your scope.'],
            ]);
        }

        if (isset($data['primary_contact_id']) && ! $company->contacts()->whereKey($data['primary_contact_id'])->exists()) {
            throw ValidationException::withMessages([
                'primary_contact_id' => ['That contact does not belong to the deal\'s company.'],
            ]);
        }

        $opportunity = DB::transaction(function () use ($data, $company, $user): Opportunity {
            /** @var Opportunity $opportunity */
            $opportunity = $company->opportunities()->create([
                'name' => $data['name'],
                'stage' => $data['stage'] ?? OpportunityStage::NewInquiry->value,
                'amount' => $data['amount'] ?? null,
                'currency' => $data['currency'] ?? 'PHP',
                'probability' => $data['probability'] ?? null,
                'expected_close_date' => $data['expected_close_date'] ?? null,
                'next_step' => $data['next_step'] ?? null,
                'source' => $data['source'] ?? $company->sourceLead?->source?->value,
                'primary_contact_id' => $data['primary_contact_id'] ?? null,
                'owner_id' => $data['owner_id'] ?? $user->id,
            ]);

            // A manager+ may hand the new deal to a teammate (module 02 §3).
            if (isset($data['owner_id'])) {
                $target = User::query()->findOrFail($data['owner_id']);

                if ($user->cannot('assign', [$opportunity, $target])) {
                    throw ValidationException::withMessages([
                        'owner_id' => ['You cannot assign this deal to that user.'],
                    ]);
                }
            }

            OpportunityCreated::dispatch($opportunity, $user);

            return $opportunity;
        });

        return (new OpportunityResource($opportunity->load($this->relations())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Opportunity $opportunity): OpportunityResource
    {
        $this->authorize('view', $opportunity);

        return new OpportunityResource($opportunity->load($this->relations()));
    }

    public function update(UpdateOpportunityRequest $request, Opportunity $opportunity): OpportunityResource
    {
        $data = $request->validated();

        if (array_key_exists('owner_id', $data)) {
            $target = User::query()->findOrFail($data['owner_id']);

            if ($request->user()->cannot('assign', [$opportunity, $target])) {
                throw ValidationException::withMessages([
                    'owner_id' => ['You cannot assign this deal to that user.'],
                ]);
            }
        }

        if (filled($data['primary_contact_id'] ?? null)
            && ! $opportunity->company->contacts()->whereKey($data['primary_contact_id'])->exists()) {
            throw ValidationException::withMessages([
                'primary_contact_id' => ['That contact does not belong to the deal\'s company.'],
            ]);
        }

        $opportunity->update($data);

        return new OpportunityResource($opportunity->load($this->relations()));
    }

    public function destroy(Opportunity $opportunity): Response
    {
        $this->authorize('delete', $opportunity);

        $opportunity->delete();

        return response()->noContent();
    }

    public function restore(string $id): OpportunityResource
    {
        $opportunity = Opportunity::withTrashed()->findOrFail($id);

        $this->authorize('restore', $opportunity);

        $opportunity->restore();

        return new OpportunityResource($opportunity->load($this->relations()));
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return ['company', 'lead', 'owner:id,name', 'primaryContact:id,name,role_type', 'contracts:id,reference,title,status,opportunity_id'];
    }
}
