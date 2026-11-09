<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 08 slice S2 (spec 19, ADR-0030): a suspended account cannot use the API.
 *
 * This is the status check on authenticated requests from the threat model — an existing,
 * still-valid JWT stops working the moment the account is suspended, because the middleware
 * re-reads the persisted status on every request rather than trusting the token. The response
 * is the standard authenticated error shape (401), and the frontend's 401 interceptor already
 * clears the session and asks for a sign-in.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            auth('api')->logout();

            return response()->json(['message' => 'Account suspended.'], 401);
        }

        return $next($request);
    }
}
