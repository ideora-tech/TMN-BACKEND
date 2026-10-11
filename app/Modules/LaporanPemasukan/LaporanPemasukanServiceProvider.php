<?php

declare(strict_types=1);

namespace App\Modules\LaporanPemasukan;

use App\Modules\LaporanPemasukan\Contracts\LaporanPemasukanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LaporanPemasukanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LaporanPemasukanRepositoryInterface::class, LaporanPemasukanRepository::class);
        $this->app->bind(LaporanPemasukanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:laporan-pemasukan'])
            ->group(function () {
                Route::get('laporan-pemasukan', [LaporanPemasukanController::class, 'index']);
                Route::get('laporan-pemasukan/export/excel', [LaporanPemasukanController::class, 'exportExcel']);
            });
    }
}
