<?php

declare(strict_types=1);

namespace App\Modules\LaporanOperasional;

use App\Modules\LaporanOperasional\Contracts\LaporanOperasionalRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LaporanOperasionalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LaporanOperasionalRepositoryInterface::class, LaporanOperasionalRepository::class);
        $this->app->bind(LaporanOperasionalService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:laporan'])
            ->group(function () {
                Route::get('laporan/trip/ringkasan', [LaporanOperasionalController::class, 'ringkasanTrip']);
                Route::get('laporan/trip/export/excel', [LaporanOperasionalController::class, 'exportTripExcel']);
                Route::get('laporan/trip/export/pdf', [LaporanOperasionalController::class, 'exportTripPdf']);
                Route::get('laporan/trip', [LaporanOperasionalController::class, 'indexTrip']);

                Route::get('laporan/karyawan/export/excel', [LaporanOperasionalController::class, 'exportKaryawanExcel']);
                Route::get('laporan/karyawan/export/pdf', [LaporanOperasionalController::class, 'exportKaryawanPdf']);

                Route::get('laporan/armada/export/excel', [LaporanOperasionalController::class, 'exportArmadaExcel']);
                Route::get('laporan/armada/export/pdf', [LaporanOperasionalController::class, 'exportArmadaPdf']);
            });
    }
}
