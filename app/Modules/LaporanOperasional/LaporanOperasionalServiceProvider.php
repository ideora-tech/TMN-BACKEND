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
            });

        // Export master menumpang izin menu masternya — tombolnya ada di halaman
        // Karyawan/Armada, bukan di halaman Laporan.
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:karyawan'])
            ->group(function () {
                Route::get('laporan/karyawan/export/excel', [LaporanOperasionalController::class, 'exportKaryawanExcel']);
                Route::get('laporan/karyawan/export/pdf', [LaporanOperasionalController::class, 'exportKaryawanPdf']);
            });

        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:armada'])
            ->group(function () {
                Route::get('laporan/armada/export/excel', [LaporanOperasionalController::class, 'exportArmadaExcel']);
                Route::get('laporan/armada/export/pdf', [LaporanOperasionalController::class, 'exportArmadaPdf']);
            });
    }
}
