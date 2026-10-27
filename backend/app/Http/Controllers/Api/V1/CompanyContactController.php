<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ContactCreated;
use App\Events\ContactPrimaryChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Contacts of one company — the Contacts tab on `/accounts/:id` (spec 07).
 */
class CompanyContactController extends Controller
{
    public function index(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);

        $contacts = $company->contacts()
            ->when($filters['role_type'] ?? null, fn ($query, $role) => $query->whereIn('role_type', explode(',', (string) $role)))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('status', explode(',', (string) $status)))
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        return ContactResource::collection($contacts);
    }

    public function store(StoreContactRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        $duplicates = Contact::emailDuplicates($company->id, $data['email'] ?? null);

        $contact = DB::transaction(function () use ($data, $company, $request): Contact {
            $previousPrimary = $company->contacts()->where('is_primary', true)->first();

            // The first contact is the company's primary by default; otherwise honour the flag.
            $isFirstContact = ! $company->contacts()->exists();

            /** @var Contact $contact */
            $contact = $company->contacts()->create([
                ...$data,
                'is_primary' => (bool) ($data['is_primary'] ?? false) || $isFirstContact,
            ]);

            if ($contact->is_primary) {
                $contact->promoteToPrimary();
            } else {
                Contact::syncPrimaryFor($company->id);
            }

            ContactCreated::dispatch($contact, $request->user());

            if ($contact->is_primary && $previousPrimary !== null && ! $previousPrimary->is($contact)) {
                ContactPrimaryChanged::dispatch($contact, $previousPrimary, $request->user());
            }

            return $contact;
        });

        return (new ContactResource($contact->load('company')))
            ->additional(['duplicates' => $duplicates])
            ->response()
            ->setStatusCode(201);
    }
}
