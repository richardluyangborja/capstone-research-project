<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CompanyStatus;
use App\Enums\ContactRoleType;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Events\CompanyCreated;
use App\Events\ContactCreated;
use App\Events\LeadConverted;
use App\Events\OpportunityCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConvertLeadRequest;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\ContactResource;
use App\Http\Resources\LeadResource;
use App\Http\Resources\OpportunityResource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lead → client conversion (spec 08): one atomic, idempotent operation that turns a
 * qualified lead into a company + contact(s) + opportunity, and nothing else.
 */
class LeadConversionController extends Controller
{
    /**
     * Suggested mapping for the wizard, plus the companies the lead looks like it may
     * already belong to (name / contact email / contact phone — advisory only, spec 08 §3).
     */
    public function preview(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $user = $request->user();
        $lead->load('owner');
        $owner = $lead->owner ?? $user;

        return response()->json([
            'data' => [
                'lead' => (new LeadResource($lead))->resolve(),
                'company' => [
                    'name' => $lead->company_name,
                    'trade_name' => null,
                    'industry' => $lead->industry,
                    'size' => null,
                    'website' => null,
                    'description' => null,
                    'owner_id' => $owner->id,
                    'owner' => ['id' => $owner->id, 'name' => $owner->name],
                ],
                'contacts' => [$this->suggestedContact($lead)],
                'opportunity' => $this->suggestedOpportunity($lead, $user, $owner),
                'duplicates' => $this->duplicateCandidates($user, $lead),
                'can_convert' => $user->can('convert', $lead) && $lead->status === LeadStatus::Qualified,
                // Sensitive amounts are manager+ only (spec 08 §5, ADR-0012).
                'can_set_amount' => $user->canSeeSensitiveData(),
                'blocked_reason' => $this->blockedReason($lead, $user),
            ],
        ]);
    }

    /**
     * Convert a qualified lead into company + contacts + opportunity (spec 08 §1–4).
     */
    public function convert(ConvertLeadRequest $request, Lead $lead): JsonResponse
    {
        $user = $request->user();

        // Idempotency: a converted lead is terminal and can never be converted twice.
        if ($lead->status === LeadStatus::Converted) {
            abort(409, 'This lead has already been converted.');
        }

        if ($lead->status !== LeadStatus::Qualified) {
            throw ValidationException::withMessages([
                'status' => ['Only qualified leads can be converted.'],
            ]);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $linkedCompany = null;

        if (filled($data['link_company_id'] ?? null)) {
            $linkedCompany = Company::query()->findOrFail($data['link_company_id']);

            // Never convert into a company outside the caller's scope (spec 08 §7).
            if ($user->cannot('view', $linkedCompany)) {
                throw ValidationException::withMessages([
                    'link_company_id' => ['That company is not in your scope.'],
                ]);
            }
        }

        $duplicates = $this->duplicateCandidates($user, $lead, $linkedCompany?->id);

        [$company, $contacts, $opportunity] = DB::transaction(function () use ($data, $lead, $user, $linkedCompany): array {
            $company = $linkedCompany ?? $this->createCompany($data['company'], $lead, $user);
            $contacts = $this->createContacts($company, $data['contacts'], $user);
            $opportunity = $this->createOpportunity($company, $contacts, $data['opportunity'], $lead, $user);

            // `converted` is terminal; the transition records the system activity and the
            // status timestamps, then the links are stamped (spec 08 §4).
            $lead->transitionTo(LeadStatus::Converted, $user, sprintf('Converted into %s.', $company->reference));
            $lead->converted_company_id = $company->id;
            $lead->converted_opportunity_id = $opportunity->id;
            $lead->save();

            LeadConverted::dispatch($lead, $company, $opportunity, $contacts, $user);

            return [$company, $contacts, $opportunity];
        });

        return response()->json([
            'data' => [
                'company' => $this->companyPayload($company),
                'contacts' => ContactResource::collection(collect($contacts))->resolve(),
                'opportunity' => (new OpportunityResource(
                    $opportunity->load(['owner', 'primaryContact', 'company', 'lead'])
                ))->resolve(),
                'lead' => (new LeadResource($lead->refresh()->load('owner')))->resolve(),
            ],
            'duplicates' => $duplicates,
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCompany(array $attributes, Lead $lead, User $actor): Company
    {
        $company = Company::create([
            'name' => $attributes['name'],
            'trade_name' => $attributes['trade_name'] ?? null,
            'industry' => $attributes['industry'] ?? $lead->industry,
            'size' => $attributes['size'] ?? null,
            'website' => $attributes['website'] ?? null,
            'description' => $attributes['description'] ?? null,
            // A converted lead is a prospect; it becomes a client on contract signed (spec 09).
            'status' => CompanyStatus::Prospect,
            'source_lead_id' => $lead->id,
            'owner_id' => $lead->owner_id ?? $actor->id,
        ]);

        CompanyCreated::dispatch($company, $actor);

        return $company;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, Contact>
     */
    private function createContacts(Company $company, array $rows, User $actor): array
    {
        // The first contact leads unless the wizard flagged another one.
        $anyFlagged = in_array(
            true,
            array_map(fn (array $row): bool => (bool) ($row['is_primary'] ?? false), $rows),
            true,
        );

        foreach ($rows as $index => $row) {
            /** @var Contact $contact */
            $contact = $company->contacts()->create([
                'name' => $row['name'],
                'position' => $row['position'] ?? null,
                'role_type' => ContactRoleType::from($row['role_type']),
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
                'mobile' => $row['mobile'] ?? null,
                'is_primary' => (bool) ($row['is_primary'] ?? false) || ($index === 0 && ! $anyFlagged),
            ]);

            ContactCreated::dispatch($contact, $actor);
        }

        // One primary per company (ADR-0011 §3) — re-derived rather than assumed.
        Contact::syncPrimaryFor($company->id);

        return $company->contacts()
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    /**
     * @param  array<int, Contact>  $contacts
     * @param  array<string, mixed>  $attributes
     */
    private function createOpportunity(
        Company $company,
        array $contacts,
        array $attributes,
        Lead $lead,
        User $actor,
    ): Opportunity {
        $primaryContact = collect($contacts)->firstWhere('is_primary', true);

        /** @var Opportunity $opportunity */
        $opportunity = $company->opportunities()->create([
            'lead_id' => $lead->id,
            'name' => $attributes['name'],
            // Conversion always opens the deal at `qualified` (spec 08 §1).
            'stage' => OpportunityStage::Qualified,
            // The lead's rate stays sensitive: it only becomes the deal amount for manager+
            // (spec 08 §5, ADR-0012). A salesrep opens the deal without an amount.
            'amount' => $actor->canSeeSensitiveData()
                ? ($attributes['amount'] ?? $lead->budget_rate)
                : null,
            'probability' => $attributes['probability'] ?? OpportunityStage::Qualified->defaultProbability(),
            'expected_close_date' => $attributes['expected_close_date'] ?? $lead->target_start_date,
            'source' => $lead->source,
            'primary_contact_id' => $primaryContact?->id,
            'owner_id' => $lead->owner_id ?? $actor->id,
        ]);

        OpportunityCreated::dispatch($opportunity, $actor);

        return $opportunity;
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestedContact(Lead $lead): array
    {
        return [
            'name' => $lead->contact_name,
            'position' => $lead->contact_position,
            // Deliberately unclassified: the wizard asks for the real decision-maker type
            // rather than assuming the enquiry came from a hiring manager.
            'role_type' => ContactRoleType::Other->value,
            'email' => $lead->contact_email,
            'phone' => $lead->contact_phone,
            'mobile' => null,
            'is_primary' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestedOpportunity(Lead $lead, User $user, User $owner): array
    {
        return [
            'name' => $this->defaultOpportunityName($lead),
            'stage' => OpportunityStage::Qualified->value,
            'amount' => $user->canSeeSensitiveData() ? $lead->budget_rate : null,
            'currency' => 'PHP',
            'probability' => OpportunityStage::Qualified->defaultProbability(),
            'expected_close_date' => $lead->target_start_date?->toDateString(),
            'source' => $lead->source,
            'owner_id' => $owner->id,
        ];
    }

    private function defaultOpportunityName(Lead $lead): string
    {
        return filled($lead->service_interest)
            ? sprintf('%s — %s', $lead->company_name, $lead->service_interest)
            : sprintf('%s staffing engagement', $lead->company_name);
    }

    private function blockedReason(Lead $lead, User $user): ?string
    {
        if ($lead->status === LeadStatus::Converted) {
            return 'This lead has already been converted.';
        }

        if ($lead->status !== LeadStatus::Qualified) {
            return sprintf('Only qualified leads can be converted — this one is %s.', $lead->status->value);
        }

        if ($user->cannot('convert', $lead)) {
            return 'You do not have permission to convert this lead.';
        }

        return null;
    }

    /**
     * Companies in the caller's scope that the lead may already be (spec 08 §3). Advisory:
     * it informs the wizard's "link to existing" option, it never blocks a conversion.
     *
     * @return array<int, array<string, mixed>>
     */
    private function duplicateCandidates(User $user, Lead $lead, ?string $excludeCompanyId = null): array
    {
        $name = mb_strtolower(trim((string) $lead->company_name));
        $email = filled($lead->contact_email) ? mb_strtolower(trim((string) $lead->contact_email)) : null;
        $phoneTail = $this->phoneTail($lead->contact_phone);

        $companies = Company::query()
            ->visibleTo($user)
            ->with('owner:id,name')
            ->when($excludeCompanyId !== null, fn (Builder $query) => $query->whereKeyNot($excludeCompanyId))
            ->where(function (Builder $query) use ($name, $email, $phoneTail): void {
                $query->whereRaw('LOWER(name) = ?', [$name])
                    ->orWhereRaw("LOWER(COALESCE(trade_name, '')) = ?", [$name]);

                if ($email !== null) {
                    $query->orWhereHas('contacts', fn (Builder $contacts) => $contacts->whereRaw('LOWER(email) = ?', [$email]));
                }

                if ($phoneTail !== null) {
                    $query->orWhereHas('contacts', fn (Builder $contacts) => $this->wherePhoneEndsWith($contacts, $phoneTail));
                }
            })
            ->limit(5)
            ->get();

        return $companies->map(function (Company $company) use ($name, $email, $phoneTail): array {
            $matched = [];

            if (mb_strtolower($company->name) === $name || mb_strtolower((string) $company->trade_name) === $name) {
                $matched[] = 'name';
            }

            if ($email !== null && $company->contacts()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                $matched[] = 'email';
            }

            if ($phoneTail !== null && $company->contacts()->where(fn (Builder $contacts) => $this->wherePhoneEndsWith($contacts, $phoneTail))->exists()) {
                $matched[] = 'phone';
            }

            return [
                'id' => $company->id,
                'reference' => $company->reference,
                'name' => $company->name,
                'trade_name' => $company->trade_name,
                'status' => $company->status,
                'owner' => $company->owner === null ? null : [
                    'id' => $company->owner->id,
                    'name' => $company->owner->name,
                ],
                'matched_on' => $matched,
            ];
        })->all();
    }

    private function wherePhoneEndsWith(Builder $query, string $tail): void
    {
        $pattern = '%'.$tail.'%';

        $query->where('phone', 'like', $pattern)->orWhere('mobile', 'like', $pattern);
    }

    /**
     * Last seven digits of a phone number — enough to match across formats without a
     * driver-specific normalisation expression.
     */
    private function phoneTail(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return strlen($digits) >= 7 ? substr($digits, -7) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function companyPayload(Company $company): array
    {
        return (new CompanyResource(
            $company->load(['owner', 'parent'])
                ->loadCount(['sites', 'children', 'contacts', 'opportunities', 'contracts', 'activities'])
        ))->resolve();
    }
}
