<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\FollowUpDueState;
use App\Http\Controllers\Controller;
use App\Http\Resources\FollowUpResource;
use App\Models\Activity;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * The follow-up worklist (module 04 slice S1, spec 12): what is owed, by when, by whom.
 *
 * It is a **read model**, never a record of its own — every row is a task, and slice S2 adds the
 * second shape (a scheduled message) beside it. Due state is computed on read against the PH
 * business day, so nothing here stores "overdue" and the clock stays testable.
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

        $base = fn (): Builder => $this->worklist($user, $filters, $search);

        $followUps = $this->applyState($base(), (string) ($filters['state'] ?? 'open'))
            // The owner's `team_id` rides along because the policy reads the owner's team
            // (ADR-0004) — see `TaskPolicy::ownerTeamId()`.
            ->with(['owner:id,name,team_id', 'subjectable'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        return FollowUpResource::collection($followUps)->additional([
            'counts' => $this->counts($base),
        ]);
    }

    /**
     * The tasks the caller may see, narrowed by the filters that do not depend on the clock.
     */
    private function worklist(User $user, array $filters, ?string $search): Builder
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
     * The state the caller asked for. `open` is the worklist's default — it lists what is owed —
     * while `overdue`/`today`/`upcoming` narrow that by the clock and `done`/`cancelled`/`all`
     * look back at what is settled.
     */
    private function applyState(Builder $query, string $state): Builder
    {
        $now = now();
        $endOfDay = FollowUpDueState::endOfBusinessDay($now);

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
     * The counters the worklist header shows, under the same filters — the overdue / today /
     * upcoming split mirrors `applyState` exactly, so a tab and its count can never disagree.
     *
     * @param  Closure(): Builder  $base
     * @return array<string, int>
     */
    private function counts(Closure $base): array
    {
        $now = now();
        $endOfDay = FollowUpDueState::endOfBusinessDay($now);

        return [
            'overdue' => $base()->where('status', ActivityStatus::Open)->where('due_at', '<', $now)->count(),
            'today' => $base()->where('status', ActivityStatus::Open)
                ->where('due_at', '>=', $now)
                ->where('due_at', '<=', $endOfDay)
                ->count(),
            'upcoming' => $base()->where('status', ActivityStatus::Open)->where('due_at', '>', $endOfDay)->count(),
            'open' => $base()->where('status', ActivityStatus::Open)->count(),
            'done' => $base()->where('status', ActivityStatus::Done)->count(),
        ];
    }
}
