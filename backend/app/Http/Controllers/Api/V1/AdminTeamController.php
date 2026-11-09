<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditSeverity;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddTeamMemberRequest;
use App\Http\Requests\AssignTeamManagerRequest;
use App\Http\Requests\StoreAdminTeamRequest;
use App\Http\Requests\UpdateAdminTeamRequest;
use App\Http\Resources\AdminTeamResource;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Team administration (module 08 slice S3, spec 19, ADR-0030).
 *
 * Teams are application-administration records: admin+ only, with manager eligibility and
 * membership scope enforced in the requests. Every mutation appends an audit row; a team id
 * is a ULID, so `audit_logs.subject_id` stays null and the snapshots carry the team id. No
 * membership change ever reassigns a lead, account or deal — ownership stays with the domain
 * delegation endpoints (spec 19 §Resource boundaries).
 */
class AdminTeamController extends Controller
{
    /**
     * Paginated team directory with a name search.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Team::class);

        $teams = Team::query()
            ->with('manager')
            ->withCount('users')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $request->input('search')).'%';
                $query->where('name', 'like', $term);
            })
            ->orderBy('name')
            ->paginate(min(max((int) $request->input('per_page', 15), 1), 100));

        return AdminTeamResource::collection($teams);
    }

    public function store(StoreAdminTeamRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $team = Team::create(['name' => $payload['name']]);

        if (isset($payload['manager_id'])) {
            $manager = $this->resolveManager((string) $payload['manager_id']);
            $team->update(['manager_id' => $manager->id]);
        }

        AuditLog::record('team.created', AuditSeverity::Info, $request->user()->id, Team::class, null, null, $this->snapshot($team));

        return response()->json(['data' => $this->detail($team)->resolve()], 201);
    }

    public function show(Request $request, Team $team): AdminTeamResource
    {
        $this->authorizeOrFail($request, 'view', $team);

        return $this->detail($team);
    }

    public function update(UpdateAdminTeamRequest $request, Team $team): AdminTeamResource
    {
        $this->authorizeOrFail($request, 'update', $team);

        $before = $this->snapshot($team);
        $team->update(['name' => $request->validated()['name']]);
        AuditLog::record('team.updated', AuditSeverity::Info, $request->user()->id, Team::class, null, $before, $this->snapshot($team));

        return $this->detail($team);
    }

    /**
     * Assigning a manager changes the leader, never the membership: the manager joins a team
     * through the member endpoint when they should also be a member.
     */
    public function assignManager(AssignTeamManagerRequest $request, Team $team): AdminTeamResource
    {
        $this->authorizeOrFail($request, 'assignManager', $team);

        $before = $this->snapshot($team);
        $manager = $this->resolveManager((string) $request->validated()['manager_id']);
        $team->update(['manager_id' => $manager->id]);
        $team->unsetRelation('manager');
        AuditLog::record('team.updated', AuditSeverity::Info, $request->user()->id, Team::class, null, $before, $this->snapshot($team));

        return $this->detail($team);
    }

    public function addMember(AddTeamMemberRequest $request, Team $team): AdminTeamResource
    {
        $this->authorizeOrFail($request, 'addMember', $team);

        $member = User::query()->where('public_id', (string) $request->validated()['user_id'])->firstOrFail();
        $before = ['user_id' => $member->public_id, 'team_id' => $member->team_id];

        $member->update(['team_id' => $team->id]);

        AuditLog::record('team.member_added', AuditSeverity::Info, $request->user()->id, Team::class, null, $before, [
            'user_id' => $member->public_id,
            'team_id' => $team->id,
        ]);

        return $this->detail($team);
    }

    /**
     * Removing the current manager is refused: a team is never left without a leader by a
     * membership call (spec 19 §S3 acceptance). Assign another manager first.
     */
    public function removeMember(Request $request, Team $team, string $user): AdminTeamResource|JsonResponse
    {
        $this->authorizeOrFail($request, 'removeMember', $team);

        $member = User::query()->where('public_id', $user)->firstOrFail();

        if ($member->team_id !== $team->id) {
            return response()->json(['message' => 'This account does not belong to the team.'], 422);
        }

        if ($team->manager_id === $member->id) {
            return response()->json(['message' => 'Assign another manager before removing this member.'], 422);
        }

        $before = ['user_id' => $member->public_id, 'team_id' => $member->team_id];
        $member->update(['team_id' => null]);

        AuditLog::record('team.member_removed', AuditSeverity::Notice, $request->user()->id, Team::class, null, $before, [
            'user_id' => $member->public_id,
            'team_id' => null,
        ]);

        return $this->detail($team);
    }

    /**
     * The detail payload: manager, counted members, and the member rows (manager first).
     */
    private function detail(Team $team): AdminTeamResource
    {
        $team->load('manager')->loadCount('users');
        $team->load(['users' => fn ($query) => $query
            ->orderByRaw("CASE WHEN role = 'manager' THEN 0 ELSE 1 END")
            ->orderBy('name')]);

        $team->users->each(function (User $member) use ($team): void {
            $member->setAttribute('is_manager', $team->manager_id === $member->id);
        });

        return new AdminTeamResource($team);
    }

    private function resolveManager(string $publicId): User
    {
        return User::query()
            ->where('public_id', $publicId)
            ->where('status', UserStatus::Active)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Team $team): array
    {
        return [
            'team_id' => $team->id,
            'name' => $team->name,
            'manager_id' => $team->manager?->public_id,
        ];
    }

    /**
     * Authorize a team mutation and, when the policy rejects it, append the
     * `security.access_denied` audit event before the standard 403 shape returns.
     */
    private function authorizeOrFail(Request $request, string $ability, Team $team): void
    {
        try {
            Gate::authorize($ability, $team);
        } catch (AuthorizationException $exception) {
            AuditLog::record('security.access_denied', AuditSeverity::Warning, $request->user()?->id, Team::class, null, null, [
                'ability' => $ability,
                'team_id' => $team->id,
            ]);

            throw $exception;
        }
    }
}
