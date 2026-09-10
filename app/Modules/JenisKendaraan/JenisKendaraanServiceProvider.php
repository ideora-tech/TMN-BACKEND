<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan;

use App\Modules\JenisKendaraan\Contracts\JenisKendaraanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class JenisKendaraanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JenisKendaraanRepositoryInterface::class, JenisKendaraanRepository::class);
        $this->app->bind(JenisKendaraanService::class);
    }

    public function boot(): void
    {
        // Baca daftar jenis kendaraan dibutuhkan form modul lain (perawatan, armada,
        // penawaran, vendor) — cukup punya izin salah satu modul itu. Tulis tetap
        // terkunci ke izin master Jenis Kendaraan.
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:jenis-kendaraan|perawatan-armada|armada|penawaran|vendor'])
            ->group(function () {
                Route::get('jenis-kendaraan', [JenisKendaraanController::class, 'index']);
                Route::get('jenis-kendaraan/{id}', [JenisKendaraanController::class, 'show']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:jenis-kendaraan'])
            ->group(function () {
                Route::post('jenis-kendaraan', [JenisKendaraanController::class, 'store']);
                Route::put('jenis-kendaraan/{id}', [JenisKendaraanController::class, 'update']);
                Route::delete('jenis-kendaraan/{id}', [JenisKendaraanController::class, 'destroy']);
            });
    }
}
