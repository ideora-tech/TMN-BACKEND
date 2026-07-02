<?php

declare(strict_types=1);

namespace App\Modules\LogError;

use App\Modules\LogError\Contracts\LogErrorRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LogErrorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LogErrorRepositoryInterface::class, LogErrorRepository::class);
        $this->app->bind(LogErrorService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::get('log-error', [LogErrorController::class, 'index']);
                Route::get('log-error/{id}', [LogErrorController::class, 'show']);
            });
    }
}
