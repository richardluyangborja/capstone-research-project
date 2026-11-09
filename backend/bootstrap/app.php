<?php

use App\Http\Middleware\ApplySettings;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Module 08 S2 — a suspended account cannot use the API (spec 19, ADR-0030).
        $middleware->alias([
            'active.user' => EnsureUserIsActive::class,
        ]);

        // Module 08 S4 — every API request reads the persisted setting overrides onto
        // `config()`, so the owning modules see the effective values on their next read.
        $middleware->api(append: [ApplySettings::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
