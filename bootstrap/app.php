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

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Log 5xx ke tabel log_error (Handler.php tidak aktif di Laravel 11)
        $exceptions->report(function (\Throwable $e) {
            if ($e instanceof \Illuminate\Validation\ValidationException)   return false;
            if ($e instanceof \Illuminate\Auth\AuthenticationException)     return false;
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                && $e->getStatusCode() < 500) return false;
            try {
                app(\App\Modules\LogError\LogErrorService::class)
                    ->write('error', $e->getMessage(), $e, request());
            } catch (\Throwable) {}
            return false; // biarkan Laravel tetap log ke file juga
        });

        // Render semua API error sebagai JSON
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (!($request->is('api/*') || $request->expectsJson())) return null;
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return \App\Helpers\ApiResponse::error('Tidak terautentikasi', null, 401);
            }
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return \App\Helpers\ApiResponse::error('Validasi gagal', $e->errors(), 422);
            }
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                return \App\Helpers\ApiResponse::error(
                    $e->getMessage() ?: 'Terjadi kesalahan', null, $e->getStatusCode()
                );
            }
            return \App\Helpers\ApiResponse::error('Terjadi kesalahan server', null, 500);
        });
    })
    ->withProviders([
        App\Providers\ModuleServiceProvider::class,
    ])
    ->create();
