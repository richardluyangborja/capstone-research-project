<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Companies\CompanyPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignCompanyRequest;
use App\Http\Requests\BulkAssignCompaniesRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

class CompanyAssignmentController extends Controller
{
    public function assign(AssignCompanyRequest $request, Company $company): CompanyResource
    {
        $target = User::findOrFail($request->integer('owner_id'));

        $this->authorize('assign', [$company, $target]);

        $company->assignTo($target, $request->user());

        return new CompanyResource(CompanyPayload::load($company->refresh()));
    }

    public function bulkAssign(BulkAssignCompaniesRequest $request): JsonResponse
    {
        $target = User::findOrFail($request->integer('owner_id'));

        /** @var Collection<int, Company> $companies */
        $companies = Company::whereIn('id', $request->input('company_ids'))->get();

        $assigned = 0;

        // Authorize every account before moving any, so a mixed batch never half-applies.
        foreach ($companies as $company) {
            $this->authorize('assign', [$company, $target]);
        }

        foreach ($companies as $company) {
            if ($company->assignTo($target, $request->user())) {
                $assigned++;
            }
        }

        $unchanged = $companies->count() - $assigned;

        return response()->json([
            'message' => sprintf('%d account(s) assigned to %s.', $assigned, $target->name),
            'data' => [
                'assigned' => $assigned,
                'unchanged' => $unchanged,
            ],
        ]);
    }
}
