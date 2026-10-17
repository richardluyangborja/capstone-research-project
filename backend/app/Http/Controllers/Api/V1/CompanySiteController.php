<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanySiteRequest;
use App\Http\Requests\UpdateCompanySiteRequest;
use App\Http\Resources\CompanySiteResource;
use App\Models\Company;
use App\Models\CompanySite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CompanySiteController extends Controller
{
    public function index(Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        return CompanySiteResource::collection(
            $company->sites()->orderByDesc('is_primary')->orderBy('label')->get(),
        );
    }

    public function store(StoreCompanySiteRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();

        $site = DB::transaction(function () use ($data, $company): CompanySite {
            // The first site is the company's primary by default; otherwise honour the flag.
            $isFirstSite = ! $company->sites()->exists();

            /** @var CompanySite $site */
            $site = $company->sites()->create([
                ...$data,
                'is_primary' => (bool) ($data['is_primary'] ?? false) || $isFirstSite,
            ]);

            if ($site->is_primary) {
                $this->unsetOtherPrimaries($company, $site);
            }

            return $site;
        });

        return (new CompanySiteResource($site))->response()->setStatusCode(201);
    }

    public function update(
        UpdateCompanySiteRequest $request,
        Company $company,
        CompanySite $site,
    ): CompanySiteResource {
        $this->authorize('manageSites', $company);
        $this->ensureSiteBelongsToCompany($company, $site);

        $data = $request->validated();

        DB::transaction(function () use ($data, $company, $site): void {
            $site->fill($data)->save();

            // One primary site per company (spec 06 §acceptance).
            if ($site->is_primary) {
                $this->unsetOtherPrimaries($company, $site);
            }
        });

        return new CompanySiteResource($site);
    }

    public function destroy(Company $company, CompanySite $site): Response
    {
        $this->authorize('manageSites', $company);
        $this->ensureSiteBelongsToCompany($company, $site);

        DB::transaction(function () use ($company, $site): void {
            $wasPrimary = $site->is_primary;

            $site->delete();

            // Keep the invariant useful: promote the oldest remaining site.
            if ($wasPrimary) {
                $company->sites()->orderBy('created_at')->first()?->update(['is_primary' => true]);
            }
        });

        return response()->noContent();
    }

    private function unsetOtherPrimaries(Company $company, CompanySite $keep): void
    {
        $company->sites()->whereKeyNot($keep->getKey())->update(['is_primary' => false]);
    }

    private function ensureSiteBelongsToCompany(Company $company, CompanySite $site): void
    {
        abort_unless($site->company_id === $company->id, 404);
    }
}
