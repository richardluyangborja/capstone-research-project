<?php

namespace App\Http\Middleware;

use App\Settings\EffectiveSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 08 slice S4 (spec 19, ADR-0030): every API request reads the effective settings.
 *
 * The persisted setting overrides are pushed onto Laravel `config()` before routing, so the
 * owning modules keep reading `config('crm.aging.stale_days')` and friends unchanged and the
 * settings surface can retune them at runtime. A key with no override row leaves the config
 * value alone — environment or default — and nothing here ever touches environment variables.
 */
class ApplySettings
{
    public function handle(Request $request, Closure $next): Response
    {
        app(EffectiveSettings::class)->apply();

        return $next($request);
    }
}
