<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OpportunityResource;
use App\Models\Company;
use App\Models\Opportunity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Opportunities tab on `/accounts/:id` (spec 06 §API, built in submodule 08).
 * Read-only: conversion opens deals, and stage moves belong to the pipeline module.
 */
class CompanyOpportunityController extends Controller
{
    public function index(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);

        $opportunities = $company->opportunities()
            ->with(['owner:id,name', 'primaryContact:id,name,role_type', 'lead:id,reference'])
            ->visibleTo($request->user())
            ->when($filters['stage'] ?? null, fn ($query, $stage) => $query->whereIn('stage', explode(',', (string) $stage)))
            ->when($filters['open'] ?? null, fn ($query) => $query->whereNotIn('stage', ['won', 'lost']))
            ->orderByDesc('created_at')
            ->get();

        return OpportunityResource::collection($opportunities)->additional([
            'meta' => [
                'total' => $opportunities->count(),
                'open' => $opportunities->filter(fn (Opportunity $opportunity): bool => $opportunity->isOpen())->count(),
            ],
        ]);
    }
}
