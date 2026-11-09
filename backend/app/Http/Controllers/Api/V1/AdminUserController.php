<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditSeverity;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeAdminUserRoleRequest;
use App\Http\Requests\ChangeAdminUserTeamRequest;
use App\Http\Requests\StoreAdminUserRequest;
use App\Http\Requests\SuspendAdminUserRequest;
use App\Http\Requests\UpdateAdminUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Integrations\EmailGateway;
use App\Integrations\OutboundEmail;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The admin user directory and account lifecycle (module 08 slice S2, spec 19, ADR-0030).
 *
 * The role matrix lives in `UserAdminPolicy`; the database-level guard — the final active
 * superadmin — lives here because it needs a count, and every block writes its audit event
 * (`security.final_superadmin_guard` critical, `security.access_denied` warning). Every
 * lifecycle mutation appends an audit row; nothing here touches CRM records.
 */
class AdminUserController extends Controller
{
    public function __construct(private readonly EmailGateway $emailGateway) {}

    /**
     * Paginated directory: search + role/team/status filters. An admin never sees superadmin
     * accounts — the filter is applied at the query, not the response.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->with(['team:id,name', 'suspender:id,name,public_id'])
            ->when($request->user()->role !== Role::Superadmin, fn ($query) => $query->where('role', '!=', Role::Superadmin))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $request->input('search')).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('role'), fn ($query) => $query->where('role', Role::tryFrom((string) $request->input('role'))))
            ->when($request->filled('team_id'), fn ($query) => $query->where('team_id', (string) $request->input('team_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', UserStatus::tryFrom((string) $request->input('status'))))
            ->orderBy('name')
            ->paginate(min(max((int) $request->input('per_page', 15), 1), 100));

        return AdminUserResource::collection($users);
    }

    /**
     * The teams that hold directory accounts, for the filter and the create form. This is a
     * lookup, not team administration — S3 owns `/admin/teams` (spec 19 §S3). It is declared
     * before the `{user:public_id}` routes so the literal path is never read as a ULID.
     */
    public function teamOptions(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $teams = Team::query()
            ->withCount('users')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Team $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'users_count' => $team->users_count,
            ]);

        return response()->json(['data' => $teams]);
    }

    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $user = User::create([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'role' => Role::from($payload['role']),
            'team_id' => $payload['team_id'] ?? null,
            'password' => $payload['password'] ?? Str::password(24, symbols: false),
            'email_verified_at' => now(),
        ]);

        $invitation = $this->sendInvitation($user);

        AuditLog::record('user.created', AuditSeverity::Notice, $request->user()->id, User::class, $user->id, null, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'team_id' => $user->team_id,
        ]);

        return response()->json([
            'data' => (new AdminUserResource($user->load(['team:id,name', 'suspender:id,name,public_id'])))->resolve(),
            'invitation' => $invitation,
        ], 201);
    }

    public function show(Request $request, User $user): AdminUserResource
    {
        $target = $user;
        $this->authorizeOrFail($request, 'view', $target);

        return new AdminUserResource(
            $target->load(['team:id,name', 'suspender:id,name,public_id'])
                ->loadCount(['leads', 'companies', 'opportunities']),
        );
    }

    /**
     * Safe profile edits only — the request rejects role/team/status/password outright, so
     * the controller can simply persist what survived validation.
     */
    public function update(UpdateAdminUserRequest $request, User $user): AdminUserResource
    {
        $target = $user;
        $this->authorizeOrFail($request, 'update', $target);

        $before = $this->profileSnapshot($target);
        // Belt and braces: even a payload that slips past `prohibited` with a null value can
        // only ever touch the two safe profile fields.
        $target->update($request->safe()->only(['name', 'email']));

        AuditLog::record('user.updated', AuditSeverity::Info, $request->user()->id, User::class, $target->id, $before, $this->profileSnapshot($target));

        return new AdminUserResource($target->load(['team:id,name', 'suspender:id,name,public_id']));
    }

    public function changeRole(ChangeAdminUserRoleRequest $request, User $user): AdminUserResource|JsonResponse
    {
        $target = $user;
        $this->authorizeOrFail($request, 'changeRole', $target);

        if ($this->wouldRemoveFinalSuperadmin($target, (string) $request->input('role'))) {
            return $this->finalSuperadminGuardResponse();
        }

        $before = ['role' => $target->role->value];
        $target->update(['role' => Role::from((string) $request->input('role'))]);

        AuditLog::record('user.role_changed', AuditSeverity::Warning, $request->user()->id, User::class, $target->id, $before, ['role' => $target->role->value]);

        return new AdminUserResource($target->load(['team:id,name', 'suspender:id,name,public_id']));
    }

    public function changeTeam(ChangeAdminUserTeamRequest $request, User $user): AdminUserResource
    {
        $target = $user;
        $this->authorizeOrFail($request, 'changeTeam', $target);

        $before = ['team_id' => $target->team_id];
        $target->update(['team_id' => $request->input('team_id')]);

        AuditLog::record('user.team_changed', AuditSeverity::Notice, $request->user()->id, User::class, $target->id, $before, ['team_id' => $target->team_id]);

        return new AdminUserResource($target->load(['team:id,name', 'suspender:id,name,public_id']));
    }

    public function suspend(SuspendAdminUserRequest $request, User $user): AdminUserResource|JsonResponse
    {
        $target = $user;
        $this->authorizeOrFail($request, 'suspend', $target);

        if ($this->wouldRemoveFinalSuperadmin($target, '')) {
            return $this->finalSuperadminGuardResponse();
        }

        if ($target->status === UserStatus::Suspended) {
            return response()->json(['message' => 'This account is already suspended.'], 422);
        }

        $target->forceFill([
            'status' => UserStatus::Suspended,
            'suspended_at' => now(),
            'suspended_by' => $request->user()->id,
            'status_reason' => (string) $request->input('status_reason'),
        ])->save();

        AuditLog::record('user.suspended', AuditSeverity::Warning, $request->user()->id, User::class, $target->id, ['status' => UserStatus::Active->value], [
            'status' => UserStatus::Suspended->value,
            'status_reason' => $target->status_reason,
        ]);

        return new AdminUserResource($target->load(['team:id,name', 'suspender:id,name,public_id']));
    }

    public function restore(Request $request, User $user): AdminUserResource
    {
        $target = $user;
        $this->authorizeOrFail($request, 'restore', $target);

        $target->forceFill([
            'status' => UserStatus::Active,
            'suspended_at' => null,
            'suspended_by' => null,
            'status_reason' => null,
        ])->save();

        AuditLog::record('user.restored', AuditSeverity::Notice, $request->user()->id, User::class, $target->id, ['status' => UserStatus::Suspended->value], ['status' => UserStatus::Active->value]);

        return new AdminUserResource($target->load(['team:id,name', 'suspender:id,name,public_id']));
    }

    /**
     * Re-send the onboarding invitation through the mocked gateway. The response never
     * carries a credential — the mocked mailbox note in the body is the only trace.
     */
    public function resendInvitation(Request $request, User $user): JsonResponse
    {
        $target = $user;
        $this->authorizeOrFail($request, 'resendInvitation', $target);

        if ($target->status === UserStatus::Suspended) {
            return response()->json(['message' => 'A suspended account cannot receive an invitation. Restore it first.'], 422);
        }

        $invitation = $this->sendInvitation($target);

        AuditLog::record('user.invitation_resent', AuditSeverity::Info, $request->user()->id, User::class, $target->id);

        return response()->json(['data' => ['sent' => $invitation['sent'], 'source' => $invitation['source']]]);
    }

    /**
     * Read-only preview of what the user owns, so a risky change is made with eyes open
     * (spec 19 §S3 acceptance, delivered early as the S2 detail panel). No mutation here.
     */
    public function ownershipImpact(Request $request, User $user): JsonResponse
    {
        $target = $user;
        $this->authorizeOrFail($request, 'viewOwnershipImpact', $target);

        return response()->json(['data' => $this->ownershipImpactFor($target)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ownershipImpactFor(User $user): array
    {
        return [
            'counts' => [
                'leads' => $user->leads()->count(),
                'companies' => $user->companies()->count(),
                'opportunities' => $user->opportunities()->count(),
                'open_follow_ups' => $user->activities()
                    ->where('type', 'task')
                    ->where('status', 'open')
                    ->count(),
            ],
            'note' => 'Suspension and team changes never reassign these records; ownership moves go through the domain delegation endpoints.',
        ];
    }

    /**
     * Hand the onboarding invitation to the mocked gateway (ADR-0005). A rejection is a
     * delivery outcome, not an error — the response carries it the same way a message send does.
     *
     * @return array<string, mixed>
     */
    private function sendInvitation(User $user): array
    {
        $origin = rtrim((string) config('crm.admin.invite_origin', 'http://localhost:5173'), '/');
        $result = $this->emailGateway->send(new OutboundEmail(
            reference: 'INV-'.substr((string) $user->public_id, -8),
            toAddress: $user->email,
            toName: $user->name,
            subject: 'Your Primepower CRM account',
            body: sprintf(
                "Hello %s,\n\nYour Primepower CRM account (%s) is ready. Sign in at %s.\n\n(This invitation was sent through the simulated mailbox; no real email left the machine.)",
                $user->name,
                $user->email,
                $origin,
            ),
        ));

        return [
            'sent' => $result->success,
            'source' => $this->emailGateway->source(),
            'message' => $result->message,
        ];
    }

    /**
     * The final-active-superadmin guard: true when the target is a superadmin, the action
     * (a demote to `$newRole`, or a suspend when `$newRole` is empty) would move them out of
     * that state, and no other active superadmin would remain.
     */
    private function wouldRemoveFinalSuperadmin(User $target, string $newRole): bool
    {
        return $target->role === Role::Superadmin
            && ($newRole === '' || $newRole !== Role::Superadmin->value)
            && User::query()->where('role', Role::Superadmin)->where('status', UserStatus::Active)->count() <= 1;
    }

    private function finalSuperadminGuardResponse(): JsonResponse
    {
        AuditLog::record('security.final_superadmin_guard', AuditSeverity::Critical, request()->user()?->id, User::class, request()->route('user')?->id);

        return response()->json(['message' => 'The final active superadmin cannot be removed.'], 422);
    }

    /**
     * Authorize a lifecycle mutation and, when the policy rejects it, append the
     * `security.access_denied` audit event (spec 19 §Audit event vocabulary) before the
     * standard 403 shape returns.
     */
    private function authorizeOrFail(Request $request, string $ability, User $target): void
    {
        try {
            Gate::authorize($ability, $target);
        } catch (AuthorizationException $exception) {
            AuditLog::record('security.access_denied', AuditSeverity::Warning, $request->user()?->id, User::class, $target->id, null, ['ability' => $ability]);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function profileSnapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
