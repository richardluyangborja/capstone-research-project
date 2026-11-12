<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditSeverity;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! $token = auth('api')->attempt($credentials)) {
            AuditLog::record('auth.login_failed', AuditSeverity::Notice, null, null, null, null, ['email' => $credentials['email']]);

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $user = auth('api')->user();

        // Module 08 S2 (spec 19, ADR-0030): a suspended account cannot authenticate, so the
        // just-minted token is discarded and the standard authenticated error shape returns.
        if ($user->status === UserStatus::Suspended) {
            auth('api')->logout();
            AuditLog::record('auth.login_failed', AuditSeverity::Notice, null, User::class, $user->id, null, ['reason' => 'suspended']);

            return response()->json(['message' => 'Account suspended.'], 401);
        }

        $payload = auth('api')->setToken($token)->getPayload();
        UserSession::query()->updateOrCreate(['jti' => (string) $payload->get('jti')], [
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes((int) auth('api')->factory()->getTTL()),
            'revoked_at' => null,
        ]);
        AuditLog::record('auth.login_succeeded', AuditSeverity::Info, $user->id, User::class, $user->id);

        return $this->tokenResponse($token);
    }

    public function me(): JsonResponse
    {
        return response()->json(['data' => auth('api')->user()]);
    }

    public function logout(): JsonResponse
    {
        $user = auth('api')->user();
        if ($user !== null) {
            $jti = (string) auth('api')->getPayload()->get('jti');
            UserSession::query()->where('user_id', $user->id)->where('jti', $jti)->update(['revoked_at' => now()]);
        }
        auth('api')->logout();

        if ($user !== null) {
            AuditLog::record('auth.logout', AuditSeverity::Info, $user->id, User::class, $user->id);
        }

        return response()->json(['message' => 'Logged out.']);
    }

    private function tokenResponse(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => auth('api')->user(),
        ]);
    }
}
