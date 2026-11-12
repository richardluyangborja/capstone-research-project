<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditSeverity;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PersonalSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewPersonalSettings');
        $user = $request->user();

        return response()->json(['data' => [
            'profile' => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value],
            'preferences' => $this->preferences($user),
            'sessions' => $user->sessions()->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
        ]]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $this->authorize('updatePersonalProfile');
        $this->rejectUnknown($request, ['name', 'email']);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,'.(int) $request->user()->id],
        ]);
        $user = $request->user();
        $before = ['name' => $user->name, 'email' => $user->email];
        $user->fill($data)->save();

        if ($before !== ['name' => $user->name, 'email' => $user->email]) {
            AuditLog::record('user.updated', AuditSeverity::Info, $user->id, User::class, $user->id, $before, ['name' => $user->name, 'email' => $user->email]);
        }

        return response()->json(['data' => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value]]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $this->authorize('updatePersonalPreferences');
        $this->rejectUnknown($request, ['date_format', 'table_density', 'default_landing_page', 'notification_database']);
        $data = $request->validate([
            'date_format' => ['sometimes', 'in:DD MMM YYYY'],
            'table_density' => ['sometimes', 'in:comfortable,compact'],
            'default_landing_page' => ['sometimes', 'in:/dashboard,/leads,/accounts,/pipeline,/follow-ups'],
            'notification_database' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $before = $this->preferences($user);
        $user->preferences = array_merge($before, $data);
        $user->save();
        $after = $this->preferences($user);

        if ($before !== $after) {
            AuditLog::record('setting.updated', AuditSeverity::Notice, $user->id, User::class, $user->id, $before, $after);
        }

        return response()->json(['data' => $after]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $this->authorize('changePersonalPassword');
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        $user->password = $data['password'];
        $user->save();
        $currentJti = $this->currentJti($user);
        $user->sessions()->whereNull('revoked_at')->where('jti', '!=', $currentJti)->update(['revoked_at' => now()]);
        AuditLog::record('auth.password_changed', AuditSeverity::Notice, $user->id, User::class, $user->id);

        return response()->json(['message' => 'Password changed. Other sessions have been signed out.']);
    }

    public function sessions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UserSession::class);
        $currentJti = $this->currentJti($request->user());
        $sessions = $request->user()->sessions()->whereNull('revoked_at')->where('expires_at', '>', now())
            ->orderByDesc('last_seen_at')->get()
            ->map(fn (UserSession $session): array => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_seen_at' => $session->last_seen_at,
                'current' => hash_equals($session->jti, $currentJti),
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function revokeSession(Request $request, UserSession $session): JsonResponse
    {
        $this->authorize('revoke', $session);
        $currentJti = $this->currentJti($request->user());
        if (hash_equals($session->jti, $currentJti)) {
            throw ValidationException::withMessages(['session' => ['Sign out other sessions instead of revoking the current session.']]);
        }
        $session->update(['revoked_at' => now()]);
        AuditLog::record('auth.session_revoked', AuditSeverity::Notice, $request->user()->id, UserSession::class, null);

        return response()->json(['message' => 'Session signed out.']);
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        $this->authorize('revokeOthers', UserSession::class);
        $currentJti = $this->currentJti($request->user());
        $count = $request->user()->sessions()->whereNull('revoked_at')->where('jti', '!=', $currentJti)->update(['revoked_at' => now()]);
        if ($count > 0) {
            AuditLog::record('auth.session_revoked', AuditSeverity::Notice, $request->user()->id, UserSession::class, null, null, ['count' => $count]);
        }

        return response()->json(['data' => ['revoked' => $count]]);
    }

    /** @param array<int, string> $allowed */
    private function rejectUnknown(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->all()), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, ['This field is not allowed.']));
        }
    }

    private function currentJti(User $user): string
    {
        try {
            return (string) auth('api')->getPayload()->get('jti');
        } catch (\Throwable) {
            return (string) $user->sessions()->whereNull('revoked_at')->value('jti');
        }
    }

    /** @return array{date_format:string,table_density:string,default_landing_page:string,notification_database:bool} */
    private function preferences(User $user): array
    {
        return array_merge([
            'date_format' => 'DD MMM YYYY',
            'table_density' => 'comfortable',
            'default_landing_page' => '/dashboard',
            'notification_database' => true,
        ], $user->preferences ?? []);
    }
}
