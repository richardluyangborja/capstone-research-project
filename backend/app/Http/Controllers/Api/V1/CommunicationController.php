<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationStatus;
use App\Events\CommunicationLogged;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommunicationRequest;
use App\Http\Requests\StoreReplyRequest;
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
use Illuminate\Support\Collection;
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
            ->withCount('attachments')
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
            // A scheduled message has no `occurred_at` yet, so narrowing by due state is what
            // finds it in time order (spec 12 §S2).
            ->when($filters['due'] ?? null, fn ($query, $due) => $query->scheduled()->due((string) $due))
            // History reads newest first; a scheduled message has not happened, so it sorts
            // after what did — spelled out because Postgres and SQLite disagree about NULLs.
            ->orderByRaw('CASE WHEN occurred_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        $base = fn () => Communication::query()->visibleTo($user);

        return CommunicationResource::collection($communications)->additional([
            'summary' => [
                'all' => $base()->count(),
                'scheduled' => $base()->scheduled()->count(),
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
     * The conversations the caller may see (spec 11 §S4): one row per threaded message group,
     * newest first, carrying the title line (the original's), the record the exchange hangs
     * off, how many messages it holds and when it last moved.
     *
     * The filters narrow *which conversations are of interest* — a thread is listed when any
     * of its messages matches — while the count and the dates describe the whole conversation,
     * so "3 messages, last Tuesday" stays true however the caller arrived at it.
     */
    public function threads(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Communication::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);
        $user = $request->user();

        // A message nobody answered is not a conversation: only rows that carry a `thread_id`
        // (the root and everything hanging off it) are grouped.
        $matching = Communication::query()
            ->visibleTo($user)
            ->whereNotNull('thread_id')
            ->search($request->string('search')->toString() ?: null)
            ->when($filters['channel'] ?? null, fn ($query, $value) => $query->whereIn('channel', explode(',', (string) $value)))
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
            ->select('thread_id')
            ->distinct();

        $threads = Communication::query()
            ->whereIn('thread_id', $matching)
            ->selectRaw('thread_id, count(*) as message_count, min(occurred_at) as first_at, max(occurred_at) as last_at')
            ->groupBy('thread_id')
            ->orderByDesc('last_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        // One more read for the rows behind the page's threads: the title, the record and who
        // sent the last word are all properties of a message, not of the group.
        $messages = Communication::query()
            ->with('subjectable')
            ->whereIn('thread_id', $threads->getCollection()->pluck('thread_id'))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->groupBy('thread_id');

        $data = $threads->getCollection()->map(function ($group) use ($messages): array {
            /** @var Collection<int, Communication> $conversation */
            $conversation = $messages->get($group->thread_id, collect());
            $root = $conversation->firstWhere('id', $group->thread_id) ?? $conversation->first();
            $last = $conversation->last();

            return [
                'thread_id' => $group->thread_id,
                'title' => $root?->subject,
                'record' => $root?->recordBlock(),
                'channel' => $root?->channel,
                'message_count' => (int) $group->message_count,
                'first_at' => Carbon::parse((string) $group->first_at)->toIso8601String(),
                'last_at' => Carbon::parse((string) $group->last_at)->toIso8601String(),
                'last_message' => $last === null ? null : [
                    'id' => $last->id,
                    'reference' => $last->reference,
                    'direction' => $last->direction,
                    'status' => $last->status,
                    'from_name' => $last->from_name,
                    'occurred_at' => $last->occurred_at?->toIso8601String(),
                ],
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $threads->currentPage(),
                'per_page' => $threads->perPage(),
                'total' => $threads->total(),
                'last_page' => $threads->lastPage(),
            ],
        ]);
    }

    /**
     * One conversation, in the order it happened (spec 11 §S4).
     *
     * A thread is visible **only if every message in it is**: one message about a record the
     * caller may not see hides the whole exchange, because it would leak what was said about
     * that record. By construction every reply inherits its parent's record, so this amounts
     * to the record's own visibility — the check is what keeps that an enforced rule rather
     * than an assumption.
     */
    public function thread(Request $request, string $threadId): JsonResponse
    {
        $this->authorize('viewAny', Communication::class);

        $messages = Communication::query()
            ->with($this->relations())
            ->withCount('attachments')
            ->where('thread_id', $threadId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            abort(404, 'That conversation does not exist.');
        }

        $user = $request->user();

        if ($messages->contains(fn (Communication $message): bool => ! $user->can('view', $message))) {
            abort(404, 'That conversation does not exist.');
        }

        $root = $messages->first();

        return response()->json([
            'data' => [
                'thread_id' => $threadId,
                'title' => $root->subject,
                'record' => $root->recordBlock(),
                'channel' => $root->channel,
                'message_count' => $messages->count(),
                'first_at' => $messages->first()->occurred_at?->toIso8601String(),
                'last_at' => $messages->last()->occurred_at?->toIso8601String(),
                'messages' => CommunicationResource::collection($messages)->resolve(),
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

        $subject = Communication::resolveSubjectType($data['subject_type'], $data['subject_id']);

        if ($subject === null) {
            throw ValidationException::withMessages([
                'subject_id' => ['That record does not exist.'],
            ]);
        }

        $this->authorize('update', $subject);

        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : null;

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
                'scheduled_for' => isset($data['scheduled_for']) ? Carbon::parse($data['scheduled_for']) : null,
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'outcome' => $data['outcome'] ?? null,
                'owner_id' => $user->id,
            ]);

            // A draft or a scheduled follow-up has not happened yet: neither gets a timeline
            // mirror, and neither may move the record's aging clock (slice S2, spec 12 §4).
            if (! in_array($communication->status, [CommunicationStatus::Draft, CommunicationStatus::Scheduled], true)) {
                CommunicationLogged::dispatch($communication, $user);
            }

            return $communication;
        });

        return (new CommunicationResource($communication->load($this->relations())))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Answer a message (spec 11 §S4). The reply joins the same conversation, points back at
     * the message it answers, and inherits what the conversation already settled: the record
     * (a reply never changes hands), its channel (an email thread stays email) and its
     * contact. It is written by the person using the app, so it goes out — the direction, like
     * everything else the parent does not answer, is whatever the caller sends.
     *
     * A reply is an ordinary history entry otherwise: logged as `sent`/`received`/`logged` it
     * writes its timeline mirror and restarts the record's clock, and saved as a `draft` it
     * waits for the gateway (slice S2) like any other outbound email.
     */
    public function reply(StoreReplyRequest $request, Communication $communication): JsonResponse
    {
        $this->authorize('reply', $communication);

        // Nothing can answer a message that never happened: a draft has not left, a scheduled
        // follow-up is still to come (module 04) and a rejected send went nowhere. A state
        // error, not a permission one — the same split as sending.
        if (($reason = $communication->replyLockReason()) !== null) {
            throw ValidationException::withMessages(['status' => [$reason]]);
        }

        $data = $request->validated();
        $user = $request->user();
        $subject = $communication->subjectable;

        if ($subject === null) {
            abort(404, 'That communication does not exist.');
        }

        $channel = CommunicationChannel::tryFrom((string) ($data['channel'] ?? '')) ?? $communication->channel;
        $direction = CommunicationDirection::tryFrom((string) ($data['direction'] ?? '')) ?? CommunicationDirection::Outbound;
        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

        $reply = DB::transaction(function () use ($communication, $subject, $data, $user, $channel, $direction, $occurredAt): Communication {
            // The message being answered opens the conversation if this is its first reply, so
            // one `thread_id` selects the whole thread — the original included.
            $threadId = $communication->openThread();

            /** @var Communication $reply */
            $reply = $subject->communications()->create([
                'contact_id' => $data['contact_id'] ?? $communication->contact_id,
                'channel' => $channel,
                'direction' => $direction,
                'status' => $data['status'] ?? null,
                'subject' => $data['subject'] ?? $communication->subject,
                'body' => $data['body'] ?? null,
                'from_name' => $data['from_name'] ?? null,
                'from_address' => $data['from_address'] ?? null,
                'to_name' => $data['to_name'] ?? null,
                'to_address' => $data['to_address'] ?? null,
                'occurred_at' => $occurredAt,
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'outcome' => $data['outcome'] ?? null,
                'thread_id' => $threadId,
                'in_reply_to_id' => $communication->id,
                'owner_id' => $user->id,
            ]);

            // A draft reply has not happened yet: no mirror, and it must not move the clock.
            if ($reply->status !== CommunicationStatus::Draft) {
                CommunicationLogged::dispatch($reply, $user);
            }

            return $reply;
        });

        return (new CommunicationResource($reply->load($this->relations())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Communication $communication): CommunicationResource
    {
        $this->authorize('view', $communication);

        return new CommunicationResource($communication->load($this->relations()));
    }

    /**
     * Send a draft — or a scheduled follow-up ahead of its time — through the email gateway
     * (spec 11 §S2, spec 12 §5). A rejected send is not an HTTP error: the record comes back
     * as `failed` with the gateway's reason, so the rep can read it and retry — and
     * `meta.source` says the gateway was a mock. This is the worklist's **Send now**.
     */
    public function send(EmailGateway $gateway, Communication $communication): JsonResponse
    {
        return $this->handToGateway(
            $gateway,
            $communication,
            [CommunicationStatus::Draft, CommunicationStatus::Scheduled],
            'a draft or a scheduled message',
        );
    }

    /**
     * Try a rejected message again (spec 11 §S2).
     */
    public function retry(EmailGateway $gateway, Communication $communication): JsonResponse
    {
        return $this->handToGateway(
            $gateway,
            $communication,
            [CommunicationStatus::Failed],
            'a message the gateway rejected',
        );
    }

    /**
     * Send every scheduled email that has fallen due (module 04 slice S2, spec 12 §5, §6).
     * Admin-only because one run writes across every team — the same reason the mailbox sync
     * is — and it is the in-app twin of `communication:send-due`.
     */
    public function dispatchDue(EmailGateway $gateway): JsonResponse
    {
        $this->authorize('dispatch', Communication::class);

        return response()->json([
            'data' => Communication::dispatchDue($gateway),
            'meta' => ['source' => $gateway->source()],
        ]);
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
     * gateway — differing only in the states they are legal from, which is a state error
     * (422), not a permission one (spec 11 §Errors).
     *
     * @param  array<int, CommunicationStatus>  $from
     */
    private function handToGateway(
        EmailGateway $gateway,
        Communication $communication,
        array $from,
        string $expected,
    ): JsonResponse {
        $this->authorize('send', $communication);

        if (! in_array($communication->status, $from, true) || ! $communication->isSendable()) {
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
            ->withCount('attachments')
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
     * @return array<int, string>
     */
    private function relations(): array
    {
        return ['owner:id,name', 'contact:id,name,email,role_type', 'subjectable', 'inReplyTo:id,reference,subject', 'attachments.uploader:id,name'];
    }
}
