<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SurveyInvitationState;
use App\Enums\SurveyInvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSurveyInvitationRequest;
use App\Http\Resources\SurveyInvitationResource;
use App\Integrations\EmailGateway;
use App\Models\Company;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The invitation book (module 05 slice S2, spec 13, ADR-0019 §3–5).
 *
 * Sending is a company action, not a survey action: it needs that account's `update` right, so
 * the store route hangs off the survey but the permission is the company's. A send is a real
 * history record — the mocked gateway hands the email out and a `Communication` keeps it — and
 * a rejection is a delivery outcome (`failed`) that `resend` retries, never an HTTP error.
 *
 * An invitation is history once it exists: it is cancelled or resent, never deleted.
 */
class SurveyInvitationController extends Controller
{
    /**
     * The book, scoped to the caller's accounts: whatever the caller may see, they may read.
     * `filter[state]` reads the derived state, so an expired-but-stored-`sent` row is found
     * where a person would look for it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SurveyInvitation::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);

        $invitations = SurveyInvitation::query()
            ->with($this->bookRelations())
            ->whereIn('company_id', Company::query()->visibleTo($request->user())->select('id'))
            ->when($filters['survey'] ?? null, fn ($query, $survey) => $query->where('survey_id', $survey))
            ->when($filters['company'] ?? null, fn ($query, $company) => $query->where('company_id', $company))
            ->filterState($filters['state'] ?? null)
            ->search($request->string('search')->toString() ?: null)
            ->latest('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        return SurveyInvitationResource::collection($invitations);
    }

    /**
     * The account's sends — the Satisfaction tab. Same rows as the book, fixed to one company.
     */
    public function forCompany(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        $invitations = $company->surveyInvitations()
            ->with($this->bookRelations())
            ->when(
                $request->string('filter.state')->toString() ?: null,
                fn ($query, $state) => $query->filterState($state),
            )
            ->latest('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        return SurveyInvitationResource::collection($invitations);
    }

    /**
     * One invitation. The public link travels while the client can still use it.
     */
    public function show(SurveyInvitation $invitation): SurveyInvitationResource
    {
        $this->authorize('view', $invitation);

        $invitation->load($this->bookRelations())->load('response.survey', 'response.company', 'response.contact');

        return new SurveyInvitationResource($invitation);
    }

    /**
     * Send a survey to one account (its `update` right). The response is 201 whether the mocked
     * gateway accepted the message or rejected it — the invitation's state says which.
     */
    public function store(StoreSurveyInvitationRequest $request, Survey $survey, EmailGateway $gateway): JsonResponse
    {
        $data = $request->validated();
        $company = Company::findOrFail($data['company_id']);

        $this->authorize('view', $survey);
        $this->authorize('update', $company);

        $invitation = DB::transaction(function () use ($request, $survey, $company, $data, $gateway): SurveyInvitation {
            /** @var SurveyInvitation $invitation */
            $invitation = SurveyInvitation::create([
                'survey_id' => $survey->id,
                'company_id' => $company->id,
                'contact_id' => $data['contact_id'] ?? null,
                'contract_id' => $data['contract_id'] ?? null,
                'recipient_name' => $request->recipientName(),
                'recipient_email' => $request->recipientEmail(),
                'message' => $data['message'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $invitation->deliver($gateway, $request->user());

            return $invitation;
        });

        return (new SurveyInvitationResource($invitation->load($this->bookRelations())))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Withdraw an invitation nobody has answered: the token stops working at once. A submitted
     * or already-cancelled one is a state error (422).
     */
    public function cancel(Request $request, SurveyInvitation $invitation): SurveyInvitationResource
    {
        $this->authorize('cancel', $invitation);

        if (! in_array($invitation->state(), [
            SurveyInvitationState::Sent,
            SurveyInvitationState::Expired,
            SurveyInvitationState::Failed,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only an invitation that has not been answered can be cancelled.'],
            ]);
        }

        $invitation->forceFill(['status' => SurveyInvitationStatus::Cancelled])->save();

        return new SurveyInvitationResource($invitation->load($this->bookRelations()));
    }

    /**
     * Hand the same invitation to the gateway again — the retry for a failed send, and a fresh
     * window for one that expired. A submitted or cancelled invitation cannot be resent: one is
     * answered history, the other was deliberately withdrawn.
     */
    public function resend(
        Request $request,
        SurveyInvitation $invitation,
        EmailGateway $gateway,
    ): SurveyInvitationResource {
        $this->authorize('resend', $invitation);

        if (in_array($invitation->status, [
            SurveyInvitationStatus::Submitted,
            SurveyInvitationStatus::Cancelled,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['This invitation can no longer be sent.'],
            ]);
        }

        $invitation->deliver($gateway, $request->user());

        return new SurveyInvitationResource($invitation->load($this->bookRelations()));
    }

    /**
     * What every invitation row carries: who it went to, which account and survey it belongs to,
     * the logged email and — once answered — the response.
     *
     * @return array<int, string>
     */
    private function bookRelations(): array
    {
        return [
            'survey:id,name,description',
            'company:id,name,owner_id',
            'contact:id,name,email',
            'contract:id,title,reference',
            'communication:id,reference,subject,status,external_id',
            'response',
        ];
    }
}
