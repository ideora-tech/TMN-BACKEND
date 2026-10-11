<?php

declare(strict_types=1);

namespace App\Modules\LaporanPengeluaran;

use App\Modules\LaporanPengeluaran\Contracts\LaporanPengeluaranRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LaporanPengeluaranServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LaporanPengeluaranRepositoryInterface::class, LaporanPengeluaranRepository::class);
        $this->app->bind(LaporanPengeluaranService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:laporan-pengeluaran'])
            ->group(function () {
                Route::get('laporan-pengeluaran', [LaporanPengeluaranController::class, 'index']);
                Route::get('laporan-pengeluaran/export/excel', [LaporanPengeluaranController::class, 'exportExcel']);
            });
    }
}
