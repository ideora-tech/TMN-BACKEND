<?php

declare(strict_types=1);

namespace App\Modules\Proyek;

use App\Modules\Proyek\Contracts\ProyekRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProyekServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProyekRepositoryInterface::class, ProyekRepository::class);
        $this->app->bind(ProyekService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:project'])
            ->group(function () {
                Route::apiResource('proyek', ProyekController::class)
                    ->parameters(['proyek' => 'id']);

                Route::patch('proyek/{id}/status', [ProyekController::class, 'updateStatus']);
            });
    }
}
