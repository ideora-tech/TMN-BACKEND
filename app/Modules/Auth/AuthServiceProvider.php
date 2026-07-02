<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthRepository::class);
        $this->app->singleton(AuthService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1/auth')
            ->middleware('api')
            ->group(function () {
                Route::post('login', [AuthController::class, 'login']);
                Route::middleware('auth:sanctum')->group(function () {
                    Route::post('logout', [AuthController::class, 'logout']);
                    Route::get('me', [AuthController::class, 'me']);
                });
            });
    }
}
