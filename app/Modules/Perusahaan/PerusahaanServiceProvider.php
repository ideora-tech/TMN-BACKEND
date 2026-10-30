<?php

declare(strict_types=1);

namespace App\Modules\Perusahaan;

use App\Modules\Perusahaan\Contracts\PerusahaanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PerusahaanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PerusahaanRepositoryInterface::class, PerusahaanRepository::class);
        $this->app->bind(PerusahaanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:perusahaan'])
            ->group(function () {
                Route::apiResource('perusahaan', PerusahaanController::class)
                    ->only(['index', 'show', 'update'])
                    ->parameters(['perusahaan' => 'id']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'role:SUPERADMIN'])
            ->group(function () {
                Route::apiResource('perusahaan', PerusahaanController::class)
                    ->only(['store', 'destroy'])
                    ->parameters(['perusahaan' => 'id']);
            });
    }
}
