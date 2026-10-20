<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\FollowUpDueState;
use App\Http\Controllers\Controller;
use App\Http\Resources\FollowUpResource;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The follow-up worklist (module 04, spec 12): what is owed, by when, by whom.
 *
 * It is a **read model**, never a record of its own. A follow-up takes two shapes — a task
 * (`Activity` `type = task`) and a scheduled message (`Communication` `status = scheduled`) —
 * and the worklist is one list over **both** (rule 2), which is why it pages a union of the two
 * tables rather than either one: ordering and paging have to happen across the pair. Due state
 * is computed on read against the PH business day, so nothing here stores "overdue".
 */
class FollowUpController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Activity::class);

        $user = $request->user();

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);
        $search = $request->string('search')->toString() ?: null;
        $state = (string) ($filters['state'] ?? 'open');
        $kind = isset($filters['kind']) ? (string) $filters['kind'] : null;

        $page = DB::query()
            ->fromSub($this->union($user, $filters, $search, $kind, $state), 'follow_ups')
            ->orderBy('due')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        /** @var Collection<int, object{kind: string, id: string}> $rows */
        $rows = $page->getCollection();

        return FollowUpResource::collection($this->hydrate($rows))->additional([
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'counts' => $this->counts($user, $filters, $search, $kind),
        ]);
    }

    /**
     * Flag every open task that has fallen due (module 04 slice S3, spec 12 §6, §10). Admin-only
     * because one run notifies owners across every team — the same reason the due sends are —
     * and it is the in-app twin of `follow-ups:notify-due`.
     */
    public function dispatchDue(): JsonResponse
    {
        $this->authorize('dispatchDue', Activity::class);

        return response()->json(['data' => Activity::notifyDue()]);
    }

    /**
     * Raise the reminders the records imply (module 04 slice S4, spec 12 §11): renewals for
     * contracts inside their renewal window, check-ins for clients nobody has contacted, and a
     * nudge for every stale lead. Manager+ — one run writes across every team — and the in-app
     * twin of `follow-ups:generate`.
     */
    public function generate(): JsonResponse
    {
        $this->authorize('generate', Activity::class);

        return response()->json(['data' => Activity::generateFollowUps()]);
    }

    /**
     * The two shapes as one paged list (rule 2). A task and a scheduled message live in
     * different tables, so the worklist is a union of the two narrowed to a common row — what
     * it is, when it is owed and when it was written — and the ordering (`due` first, the
     * oldest written first on a tie) happens over that row.
     *
     * `kind` narrows to one shape by emptying the other half, and `state` is applied to each
     * half separately because the two express it differently: a task is `open`/`done`/
     * `cancelled` and a scheduled message simply is not yet sent.
     */
    private function union(User $user, array $filters, ?string $search, ?string $kind, string $state): QueryBuilder
    {
        $now = now();
        $endOfDay = FollowUpDueState::endOfBusinessDay($now);

        $tasks = $this->tasks($user, $filters, $search)
            ->when($kind === 'message', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->selectRaw("'task' as kind")
            ->addSelect(['id', 'created_at'])
            ->selectRaw('due_at as due');

        $this->applyTaskState($tasks, $state, $now, $endOfDay);

        $messages = $this->messages($user, $filters, $search)
            ->when($kind === 'task', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->selectRaw("'message' as kind")
            ->addSelect(['id', 'created_at'])
            ->selectRaw('scheduled_for as due');

        $this->applyMessageState($messages, $state, $now, $endOfDay);

        return $tasks->toBase()->unionAll($messages->toBase());
    }

    /**
     * The tasks the caller may see, narrowed by the filters that do not depend on the clock.
     */
    private function tasks(User $user, array $filters, ?string $search): Builder
    {
        return Activity::query()
            ->visibleTo($user)
            ->tasks()
            ->search($search)
            ->when($filters['owner'] ?? null, fn (Builder $query, $owner) => match ((string) $owner) {
                'me' => $query->where('owner_id', $user->id),
                'team' => $query->whereHas('owner', fn (Builder $q) => $q->where('team_id', $user->team_id)),
                'all' => $query,
                default => $query->where('owner_id', $owner),
            })
            ->when($filters['record_type'] ?? null, fn (Builder $query, $type) => $query->where(
                'subject_type',
                Activity::subjectTypes()[(string) $type] ?? (string) $type,
            ))
            ->when($filters['record_id'] ?? null, fn (Builder $query, $id) => $query->where('subject_id', $id))
            ->when($filters['due_from'] ?? null, fn (Builder $query, $date) => $query
                ->where('due_at', '>=', Carbon::parse((string) $date)->startOfDay()))
            ->when($filters['due_to'] ?? null, fn (Builder $query, $date) => $query
                ->where('due_at', '<=', Carbon::parse((string) $date)->endOfDay()));
    }

    /**
     * The scheduled messages the caller may see — the worklist's second shape (slice S2). The
     * base is always `scheduled`: what was sent is history, not something still owed.
     */
    private function messages(User $user, array $filters, ?string $search): Builder
    {
        return Communication::query()
            ->visibleTo($user)
            ->scheduled()
            ->search($search)
            ->when($filters['owner'] ?? null, fn (Builder $query, $owner) => match ((string) $owner) {
                'me' => $query->where('owner_id', $user->id),
                'team' => $query->whereHas('owner', fn (Builder $q) => $q->where('team_id', $user->team_id)),
                'all' => $query,
                default => $query->where('owner_id', $owner),
            })
            ->when($filters['record_type'] ?? null, fn (Builder $query, $type) => $query->where(
                'subject_type',
                Communication::subjectTypes()[(string) $type] ?? (string) $type,
            ))
            ->when($filters['record_id'] ?? null, fn (Builder $query, $id) => $query->where('subject_id', $id))
            ->when($filters['due_from'] ?? null, fn (Builder $query, $date) => $query
                ->where('scheduled_for', '>=', Carbon::parse((string) $date)->startOfDay()))
            ->when($filters['due_to'] ?? null, fn (Builder $query, $date) => $query
                ->where('scheduled_for', '<=', Carbon::parse((string) $date)->endOfDay()));
    }

    /**
     * The task half of the state the caller asked for. `open` is the worklist's default — it
     * lists what is owed — while `overdue`/`today`/`upcoming` narrow that by the clock and
     * `done`/`cancelled`/`all` look back at what is settled.
     */
    private function applyTaskState(Builder $query, string $state, CarbonInterface $now, CarbonInterface $endOfDay): Builder
    {
        return match ($state) {
            'overdue' => $query->where('status', ActivityStatus::Open)->where('due_at', '<', $now),
            'today' => $query->where('status', ActivityStatus::Open)
                ->where('due_at', '>=', $now)
                ->where('due_at', '<=', $endOfDay),
            'upcoming' => $query->where('status', ActivityStatus::Open)->where('due_at', '>', $endOfDay),
            'done' => $query->where('status', ActivityStatus::Done),
            'cancelled' => $query->where('status', ActivityStatus::Cancelled),
            'all' => $query,
            default => $query->where('status', ActivityStatus::Open),
        };
    }

    /**
     * The message half of the same state. A scheduled message is still owed until it goes out,
     * so it has no finished state to look back at: `done`/`cancelled` list tasks only, and every
     * other state narrows its `scheduled_for` by the clock exactly as the task half does.
     */
    private function applyMessageState(Builder $query, string $state, CarbonInterface $now, CarbonInterface $endOfDay): Builder
    {
        return match ($state) {
            'done', 'cancelled' => $query->whereRaw('1 = 0'),
            'overdue' => $query->where('scheduled_for', '<', $now),
            'today' => $query->where('scheduled_for', '>=', $now)->where('scheduled_for', '<=', $endOfDay),
            'upcoming' => $query->where('scheduled_for', '>', $endOfDay),
            default => $query,
        };
    }

    /**
     * Turn the union's rows back into the records they name, keeping the page's order — two
     * reads for a page, never one per row.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, Model>
     */
    private function hydrate(Collection $rows): Collection
    {
        $tasks = Activity::query()
            // The owner's `team_id` rides along because the policy reads the owner's team
            // (ADR-0004) — see `TaskPolicy::ownerTeamId()`.
            ->with(['owner:id,name,team_id', 'subjectable'])
            ->whereIn('id', $rows->where('kind', 'task')->pluck('id'))
            ->get()
            ->keyBy('id');

        $messages = Communication::query()
            ->with(['owner:id,name,team_id', 'contact:id,name,email', 'subjectable'])
            ->whereIn('id', $rows->where('kind', 'message')->pluck('id'))
            ->get()
            ->keyBy('id');

        return $rows
            ->map(fn (object $row): ?Model => $row->kind === 'task'
                ? $tasks->get($row->id)
                : $messages->get($row->id))
            ->filter()
            ->values();
    }

    /**
     * The counters the worklist header shows, under the same filters — the overdue / today /
     * upcoming split mirrors the state filter exactly, so a tab and its count can never
     * disagree. The open counters run over the same union the list does, in one aggregate;
     * `done` looks only at tasks, because a message is never done.
     *
     * @return array<string, int>
     */
    private function counts(User $user, array $filters, ?string $search, ?string $kind): array
    {
        $now = now();
        $endOfDay = FollowUpDueState::endOfBusinessDay($now);

        $row = DB::query()
            ->fromSub($this->union($user, $filters, $search, $kind, 'open'), 'follow_ups')
            ->selectRaw(
                'count(*) as open, '
                .'sum(case when due < ? then 1 else 0 end) as overdue, '
                .'sum(case when due >= ? and due <= ? then 1 else 0 end) as today, '
                .'sum(case when due > ? then 1 else 0 end) as upcoming',
                [$now, $now, $endOfDay, $endOfDay],
            )
            ->first();

        $done = $this->tasks($user, $filters, $search)
            ->when($kind === 'message', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('status', ActivityStatus::Done)
            ->count();

        return [
            'overdue' => (int) ($row->overdue ?? 0),
            'today' => (int) ($row->today ?? 0),
            'upcoming' => (int) ($row->upcoming ?? 0),
            'open' => (int) ($row->open ?? 0),
            'done' => $done,
        ];
    }
}
