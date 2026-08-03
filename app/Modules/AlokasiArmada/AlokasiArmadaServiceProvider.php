<?php

declare(strict_types=1);

namespace App\Modules\AlokasiArmada;

use App\Modules\AlokasiArmada\Contracts\AlokasiArmadaRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AlokasiArmadaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AlokasiArmadaRepositoryInterface::class, AlokasiArmadaRepository::class);
        $this->app->bind(AlokasiArmadaService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:penugasan'])
            ->group(function () {
                Route::get('alokasi-armada', [AlokasiArmadaController::class, 'index']);
                Route::get('alokasi-armada/armada-tersedia', [AlokasiArmadaController::class, 'armadaTersedia']);
                Route::get('alokasi-armada/riwayat', [AlokasiArmadaController::class, 'riwayat']);
                Route::get('alokasi-armada/export/excel', [AlokasiArmadaController::class, 'exportExcel']);
                Route::get('alokasi-armada/export/pdf', [AlokasiArmadaController::class, 'exportPdf']);
                Route::put('alokasi-armada/{id}', [AlokasiArmadaController::class, 'update']);
            });
    }
}
