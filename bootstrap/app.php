<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // For API-only app: redirect guests to null so auth middleware throws
        // AuthenticationException instead of trying to redirect to route('login')
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Ensure unauthenticated API requests return JSON 401, not a redirect to route('login')
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return \App\Helpers\ApiResponse::error('Tidak terautentikasi', null, 401);
            }
        });
    })
    ->withProviders([
        App\Providers\ModuleServiceProvider::class,
    ])
    ->create();
