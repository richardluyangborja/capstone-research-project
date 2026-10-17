<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ContactPrimaryChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Flat contact routes (spec 07): global list, detail, update, retire/restore and the explicit
 * "make primary" move. Creation lives under its company (`CompanyContactController`).
 */
class ContactController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Contact::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);

        $contacts = Contact::query()
            ->with('company:id,reference,name,status')
            ->visibleTo($request->user())
            ->search($request->string('search')->toString() ?: null)
            ->when($filters['company_id'] ?? null, fn ($query, $company) => $query->where('company_id', $company))
            ->when($filters['role_type'] ?? null, fn ($query, $role) => $query->whereIn('role_type', explode(',', (string) $role)))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('status', explode(',', (string) $status)))
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        return ContactResource::collection($contacts);
    }

    public function show(Contact $contact): ContactResource
    {
        $this->authorize('view', $contact);

        return new ContactResource($contact->load('company'));
    }

    public function update(UpdateContactRequest $request, Contact $contact): ContactResource
    {
        $data = $request->validated();
        $duplicates = Contact::emailDuplicates(
            $contact->company_id,
            $data['email'] ?? $contact->email,
            $contact->id,
        );

        DB::transaction(function () use ($data, $contact, $request): void {
            $previousPrimary = $contact->is_primary
                ? $contact
                : Contact::query()->where('company_id', $contact->company_id)->where('is_primary', true)->first();

            $contact->fill($data)->save();

            // Keep "exactly one primary per company" true after every change: an explicit flag
            // promotes this contact, otherwise the invariant is re-derived — so deactivating
            // the primary hands the flag to the oldest remaining active contact.
            if ($data['is_primary'] ?? false) {
                $contact->promoteToPrimary();
                $primary = $contact;
            } else {
                $primary = Contact::syncPrimaryFor($contact->company_id);
            }

            if ($primary !== null && ! $primary->is($previousPrimary)) {
                ContactPrimaryChanged::dispatch($primary, $previousPrimary, $request->user());
            }
        });

        return (new ContactResource($contact->refresh()->load('company')))
            ->additional(['duplicates' => $duplicates]);
    }

    /**
     * Retire a contact (soft delete — spec 07 §6: contacts are never hard deleted).
     */
    public function destroy(Contact $contact): Response
    {
        $this->authorize('delete', $contact);

        DB::transaction(function () use ($contact): void {
            $previousPrimary = $contact->is_primary ? $contact : null;

            if ($contact->is_primary) {
                $contact->forceFill(['is_primary' => false])->save();
            }

            $contact->delete();

            $primary = Contact::syncPrimaryFor($contact->company_id);

            if ($previousPrimary !== null && $primary !== null) {
                ContactPrimaryChanged::dispatch($primary, $previousPrimary);
            }
        });

        return response()->noContent();
    }

    public function restore(string $id): ContactResource
    {
        $contact = Contact::withTrashed()->findOrFail($id);

        $this->authorize('restore', $contact);

        DB::transaction(function () use ($contact): void {
            $contact->restore();

            // A returning contact only takes the flag if the company has none left.
            Contact::syncPrimaryFor($contact->company_id);
        });

        return new ContactResource($contact->refresh()->load('company'));
    }

    /**
     * Set the company's primary contact (spec 07 §API — `POST /contacts/{id}/make-primary`).
     */
    public function makePrimary(Contact $contact): ContactResource
    {
        $this->authorize('makePrimary', $contact);

        // Pickers only ever show active contacts (spec 07 §6), so an inactive one cannot lead.
        if (! $contact->isActive()) {
            throw ValidationException::withMessages([
                'status' => ['Activate this contact before making it the primary one.'],
            ]);
        }

        DB::transaction(function () use ($contact): void {
            $previous = Contact::query()
                ->where('company_id', $contact->company_id)
                ->where('is_primary', true)
                ->first();

            $contact->promoteToPrimary();

            if (! $previous?->is($contact)) {
                ContactPrimaryChanged::dispatch($contact, $previous);
            }
        });

        return new ContactResource($contact->refresh()->load('company'));
    }
}
