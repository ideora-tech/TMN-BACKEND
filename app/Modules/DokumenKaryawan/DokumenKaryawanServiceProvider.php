<?php

declare(strict_types=1);

namespace App\Modules\DokumenKaryawan;

use App\Modules\DokumenKaryawan\Contracts\DokumenKaryawanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DokumenKaryawanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DokumenKaryawanRepositoryInterface::class, DokumenKaryawanRepository::class);
        $this->app->bind(DokumenKaryawanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:karyawan'])
            ->group(function () {
                Route::get('dokumen-karyawan', [DokumenKaryawanController::class, 'index']);

                Route::get('karyawan/{idKaryawan}/dokumen', [DokumenKaryawanController::class, 'indexByKaryawan']);
                Route::post('karyawan/{idKaryawan}/dokumen', [DokumenKaryawanController::class, 'store']);
                Route::put('karyawan/{idKaryawan}/dokumen/{id}', [DokumenKaryawanController::class, 'update']);
                Route::delete('karyawan/{idKaryawan}/dokumen/{id}', [DokumenKaryawanController::class, 'destroy']);
            });
    }
}
