<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CommunicationStatus;
use App\Events\CommunicationLogged;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommunicationRequest;
use App\Http\Requests\UpdateCommunicationRequest;
use App\Http\Resources\CommunicationResource;
use App\Integrations\EmailGateway;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The communication history book (module 03): log a channel-aware entry against a lead,
 * account, contact, deal or contract, then read, correct and retire it. Slice S2 adds the
 * outbound email path — a draft goes to the mocked gateway and its reply is pulled back.
 *
 * Every entry that has happened also writes an activity mirror on the subject's timeline, so
 * the timeline endpoints from modules 01–02 need no change (ADR-0017 §2).
 */
class CommunicationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Communication::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);
        $user = $request->user();

        $communications = Communication::query()
            ->with(['owner:id,name', 'contact:id,name,email,role_type', 'subjectable'])
            ->visibleTo($user)
            ->search($request->string('search')->toString() ?: null)
            ->when($filters['channel'] ?? null, fn ($query, $value) => $query->whereIn('channel', explode(',', (string) $value)))
            ->when($filters['direction'] ?? null, fn ($query, $value) => $query->whereIn('direction', explode(',', (string) $value)))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->whereIn('status', explode(',', (string) $value)))
            ->when($filters['subject_type'] ?? null, fn ($query, $type) => $query->where(
                'subject_type',
                Communication::subjectTypes()[(string) $type] ?? (string) $type,
            ))
            ->when($filters['subject_id'] ?? null, fn ($query, $id) => $query->where('subject_id', $id))
            ->when($filters['company'] ?? null, fn ($query, $company) => $query
                ->where('subject_type', Company::class)
                ->where('subject_id', $company))
            ->when($filters['contact'] ?? null, fn ($query, $contact) => $query->where('contact_id', $contact))
            ->when($filters['owner'] ?? null, fn ($query, $owner) => match ($owner) {
                'me' => $query->where('owner_id', $user->id),
                'team' => $query->whereHas('owner', fn ($q) => $q->where('team_id', $user->team_id)),
                default => $query->where('owner_id', $owner),
            })
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->where('occurred_at', '>=', Carbon::parse((string) $date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->where('occurred_at', '<=', Carbon::parse((string) $date)->endOfDay()))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        $base = fn () => Communication::query()->visibleTo($user);

        return CommunicationResource::collection($communications)->additional([
            'summary' => [
                'all' => $base()->count(),
                'by_direction' => $base()
                    ->selectRaw('direction, count(*) as total')
                    ->groupBy('direction')
                    ->pluck('total', 'direction')
                    ->all(),
                'by_channel' => $base()
                    ->selectRaw('channel, count(*) as total')
                    ->groupBy('channel')
                    ->pluck('total', 'channel')
                    ->all(),
                'last_contacted_at' => $base()->max('occurred_at'),
            ],
        ]);
    }

    /**
     * Log a communication against a record (spec 11 §S1). The caller must be able to work
     * the record it is logged against — you may only tell the story of a record you own.
     */
    public function store(StoreCommunicationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $subject = $this->resolveSubject($data['subject_type'], $data['subject_id']);

        if ($subject === null) {
            throw ValidationException::withMessages([
                'subject_id' => ['That record does not exist.'],
            ]);
        }

        $this->authorize('update', $subject);

        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

        $communication = DB::transaction(function () use ($data, $subject, $user, $occurredAt): Communication {
            /** @var Communication $communication */
            $communication = $subject->communications()->create([
                'contact_id' => $data['contact_id'] ?? null,
                'channel' => $data['channel'],
                'direction' => $data['direction'],
                'status' => $data['status'] ?? null,
                'subject' => $data['subject'],
                'body' => $data['body'] ?? null,
                'from_name' => $data['from_name'] ?? null,
                'from_address' => $data['from_address'] ?? null,
                'to_name' => $data['to_name'] ?? null,
                'to_address' => $data['to_address'] ?? null,
                'occurred_at' => $occurredAt,
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'outcome' => $data['outcome'] ?? null,
                'owner_id' => $user->id,
            ]);

            // A draft has not happened yet: it gets no timeline mirror, and it must not move
            // the record's aging clock (slice S2).
            if ($communication->status !== CommunicationStatus::Draft) {
                CommunicationLogged::dispatch($communication, $user);
            }

            return $communication;
        });

        return (new CommunicationResource($communication->load($this->relations())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Communication $communication): CommunicationResource
    {
        $this->authorize('view', $communication);

        return new CommunicationResource($communication->load($this->relations()));
    }

    /**
     * Send a draft through the email gateway (spec 11 §S2). A rejected send is not an HTTP
     * error: the record comes back as `failed` with the gateway's reason, so the rep can read
     * it and retry — and `meta.source` says the gateway was a mock.
     */
    public function send(EmailGateway $gateway, Communication $communication): JsonResponse
    {
        return $this->handToGateway($gateway, $communication, CommunicationStatus::Draft);
    }

    /**
     * Try a rejected message again (spec 11 §S2).
     */
    public function retry(EmailGateway $gateway, Communication $communication): JsonResponse
    {
        return $this->handToGateway($gateway, $communication, CommunicationStatus::Failed);
    }

    /**
     * Pull the mocked mailbox's replies into the history (spec 11 §S2, §Business rules 7).
     * Inbound is pulled, never pushed, and nothing runs on a timer in this phase — so the demo
     * needs a way to trigger the same job the `communication:sync-inbound` command runs.
     * Admin-only: a sync rewrites history records across every team.
     */
    public function syncInbound(EmailGateway $gateway): JsonResponse
    {
        $this->authorize('sync', Communication::class);

        return response()->json([
            'data' => ['recorded' => Communication::pullInbound($gateway)],
            'meta' => ['source' => $gateway->source()],
        ]);
    }

    public function update(UpdateCommunicationRequest $request, Communication $communication): CommunicationResource
    {
        // Immutability is a state rule, not a permission: a delivered or received message is
        // a record of what was said (spec 11 §Business rules 4, ADR-0017 §6).
        if (! $communication->isEditable()) {
            throw ValidationException::withMessages([
                'status' => [sprintf('A %s message cannot be edited.', $communication->status->value)],
            ]);
        }

        $communication->update($request->validated());

        // Keep the timeline row in step with the corrected title.
        $communication->syncActivityMirror();

        return new CommunicationResource($communication->load($this->relations()));
    }

    /**
     * The shared send/retry flow. Both are the same act — hand an outbound email to the
     * gateway — differing only in the state they are legal from, which is a state error
     * (422), not a permission one (spec 11 §Errors).
     */
    private function handToGateway(
        EmailGateway $gateway,
        Communication $communication,
        CommunicationStatus $from,
    ): JsonResponse {
        $this->authorize('send', $communication);

        $expected = $from === CommunicationStatus::Draft
            ? 'a draft'
            : 'a message the gateway rejected';

        if ($communication->status !== $from || ! $communication->isSendable()) {
            throw ValidationException::withMessages([
                'status' => [sprintf('Only %s can go to the mail gateway.', $expected)],
            ]);
        }

        // A rep may pick a person instead of typing an address; a send needs a destination.
        $communication->fillRecipientFromContact();

        if (($communication->to_address ?? '') === '') {
            throw ValidationException::withMessages([
                'to_address' => ['Add a recipient before sending — pick a contact or type an address.'],
            ]);
        }

        $communication = $communication->sendThrough($gateway);

        return (new CommunicationResource($communication->load($this->relations())))
            ->additional(['meta' => ['source' => $gateway->source()]])
            ->response();
    }

    public function destroy(Communication $communication): Response
    {
        $this->authorize('delete', $communication);

        $communication->delete();

        return response()->noContent();
    }

    public function restore(string $id): CommunicationResource
    {
        $communication = Communication::withTrashed()->findOrFail($id);

        $this->authorize('restore', $communication);

        // The model hook rewrites the timeline mirror the delete removed.
        $communication->restore();

        return new CommunicationResource($communication->load($this->relations()));
    }

    public function forLead(Request $request, Lead $lead): AnonymousResourceCollection
    {
        return $this->history($request, $lead);
    }

    public function forCompany(Request $request, Company $company): AnonymousResourceCollection
    {
        return $this->history($request, $company);
    }

    public function forContact(Request $request, Contact $contact): AnonymousResourceCollection
    {
        return $this->history($request, $contact);
    }

    public function forOpportunity(Request $request, Opportunity $opportunity): AnonymousResourceCollection
    {
        return $this->history($request, $opportunity);
    }

    public function forContract(Request $request, Contract $contract): AnonymousResourceCollection
    {
        return $this->history($request, $contract);
    }

    /**
     * The messages logged directly against one record — the history tab on its page.
     */
    private function history(
        Request $request,
        Lead|Company|Contact|Opportunity|Contract $subject,
    ): AnonymousResourceCollection {
        $this->authorize('view', $subject);

        $communications = $subject->communications()
            ->with(['owner:id,name', 'contact:id,name,email,role_type'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        // The subject is the route model — no need to re-query it per row.
        $communications->each(fn (Communication $communication) => $communication->setRelation('subjectable', $subject));

        return CommunicationResource::collection($communications)->additional([
            'meta' => ['total' => $communications->count()],
        ]);
    }

    /**
     * The short `subject_type` the API accepts, resolved to the record it names.
     */
    private function resolveSubject(string $type, string $id): Lead|Company|Contact|Opportunity|Contract|null
    {
        $class = Communication::subjectTypes()[$type] ?? null;

        return $class === null ? null : $class::query()->find($id);
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return ['owner:id,name', 'contact:id,name,email,role_type', 'subjectable'];
    }
}
