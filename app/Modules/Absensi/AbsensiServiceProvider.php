<?php

declare(strict_types=1);

namespace App\Modules\Absensi;

use App\Modules\Absensi\Contracts\AbsensiRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AbsensiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AbsensiRepositoryInterface::class, AbsensiRepository::class);
        $this->app->bind(AbsensiService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:karyawan'])
            ->group(function () {
                Route::get('absensi/saya/hari-ini', [AbsensiController::class, 'absensiSaya']);
                Route::post('absensi/saya/masuk', [AbsensiController::class, 'absenMasuk']);
                Route::post('absensi/saya/pulang', [AbsensiController::class, 'absenPulang']);
                Route::get('absensi/harian', [AbsensiController::class, 'harian']);
                Route::post('absensi/harian', [AbsensiController::class, 'simpanHarian']);
                Route::get('absensi/rekap', [AbsensiController::class, 'rekap']);
                Route::get('absensi/pengaturan', [AbsensiController::class, 'pengaturan']);
                Route::put('absensi/pengaturan', [AbsensiController::class, 'simpanPengaturan']);
            });
    }
}
