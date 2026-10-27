<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CompanyStatus;
use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCompanyLifecycleRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The client lifecycle (spec 09 §1, §5): status moves along the state machine, win-back,
 * and the retry of a failed (mocked) Service handoff.
 */
class CompanyLifecycleController extends Controller
{
    public function update(UpdateCompanyLifecycleRequest $request, Company $company): CompanyResource
    {
        $data = $request->validated();

        $company->transitionTo(
            CompanyStatus::from($data['status']),
            $request->user(),
            $data['reason'] ?? null,
            $data['note'] ?? null,
        );

        return new CompanyResource($this->present($company));
    }

    /**
     * Win-back: a churned client returns to active, keeping its original `client_since`.
     */
    public function reactivate(Request $request, Company $company): CompanyResource
    {
        $this->authorize('manageLifecycle', $company);

        if (! $company->status->isChurned()) {
            throw ValidationException::withMessages([
                'status' => ['Only a churned client can be reactivated.'],
            ]);
        }

        $company->transitionTo(
            CompanyStatus::Active,
            $request->user(),
            null,
            $request->string('note')->toString() ?: null,
        );

        return new CompanyResource($this->present($company));
    }

    /**
     * Retry the Service handoff that failed when a contract was signed (spec 09 §2). The
     * same orchestration as signing runs, so a success activates the client.
     */
    public function retryHandoff(Request $request, Company $company): CompanyResource
    {
        $this->authorize('retryHandoff', $company);

        $contract = $company->contracts()
            ->where('status', ContractStatus::Signed->value)
            ->latest('signed_at')
            ->first();

        if ($contract === null) {
            throw ValidationException::withMessages([
                'handoff' => ['This client has no signed contract to hand off.'],
            ]);
        }

        $company->handoffToService($contract, $request->user());

        return new CompanyResource($this->present($company));
    }

    private function present(Company $company): Company
    {
        return $company
            ->load(['owner', 'parent'])
            ->loadCount(['sites', 'children', 'contacts', 'opportunities', 'openOpportunities as open_opportunities_count', 'contracts', 'activities', 'communications']);
    }
}
