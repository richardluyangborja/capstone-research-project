<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicLeadRequest;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;

/**
 * Public request-staffing intake (spec 16 §Public intake, ADR-0026 §3).
 *
 * The endpoint is unauthenticated and deliberately narrow: it creates an unassigned website
 * lead with consent recorded, and returns only a friendly message. Internal ids, duplicate
 * matches and protected fields never travel back to the visitor.
 */
class PublicLeadController extends Controller
{
    public function store(StorePublicLeadRequest $request): JsonResponse
    {
        $data = $request->validated();

        Lead::create([
            'company_name' => $data['company_name'],
            'contact_name' => $data['contact_name'],
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'industry' => $data['industry'] ?? null,
            'service_interest' => $data['service_interest'] ?? null,
            'estimated_headcount' => $data['estimated_headcount'] ?? null,
            'needed_roles' => $data['needed_roles'] ?? null,
            'target_start_date' => $data['target_start_date'] ?? null,
            'notes' => $data['message'] ?? null,
            'source' => LeadSource::Website,
            'source_detail' => 'Public landing form',
            'consent_at' => now(),
        ]);

        return response()->json([
            'message' => 'Thank you — your staffing request has been sent. Our team will be in touch.',
        ], 201);
    }
}
