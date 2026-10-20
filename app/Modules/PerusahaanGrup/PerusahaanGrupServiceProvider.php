<?php

declare(strict_types=1);

namespace App\Modules\PerusahaanGrup;

use App\Modules\PerusahaanGrup\Contracts\PerusahaanGrupRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PerusahaanGrupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PerusahaanGrupRepositoryInterface::class, PerusahaanGrupRepository::class);
        $this->app->bind(PerusahaanGrupService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:perusahaan-grup|armada'])
            ->group(function () {
                Route::get('perusahaan-grup/opsi-aktif', [PerusahaanGrupController::class, 'opsiAktif']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:perusahaan-grup'])
            ->group(function () {
                Route::apiResource('perusahaan-grup', PerusahaanGrupController::class)
                    ->parameters(['perusahaan-grup' => 'id']);
            });
    }
}
