<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek;

use App\Modules\LaporanProyek\Contracts\LaporanProyekRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LaporanProyekServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LaporanProyekRepositoryInterface::class, LaporanProyekRepository::class);
        $this->app->bind(LaporanProyekService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:laporan'])
            ->group(function () {
                Route::get('laporan/export/excel', [LaporanProyekController::class, 'exportExcel']);
                Route::get('laporan/export/pdf', [LaporanProyekController::class, 'exportPdf']);

                Route::get('laporan', [LaporanProyekController::class, 'index']);
                Route::post('laporan', [LaporanProyekController::class, 'store']);
                Route::get('laporan/{id}', [LaporanProyekController::class, 'show']);
                Route::put('laporan/{id}', [LaporanProyekController::class, 'update']);
                Route::get('proyek/{idProyek}/laporan', [LaporanProyekController::class, 'showByProyek']);
            });
    }
}
