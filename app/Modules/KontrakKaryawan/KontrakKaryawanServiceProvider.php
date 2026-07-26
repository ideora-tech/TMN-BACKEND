<?php

declare(strict_types=1);

namespace App\Modules\KontrakKaryawan;

use App\Modules\KontrakKaryawan\Contracts\KontrakKaryawanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KontrakKaryawanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KontrakKaryawanRepositoryInterface::class, KontrakKaryawanRepository::class);
        $this->app->bind(KontrakKaryawanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:karyawan'])
            ->group(function () {
                Route::get('karyawan/{idKaryawan}/kontrak', [KontrakKaryawanController::class, 'index']);
                Route::post('karyawan/{idKaryawan}/kontrak', [KontrakKaryawanController::class, 'store']);
                Route::put('karyawan/{idKaryawan}/kontrak/{id}', [KontrakKaryawanController::class, 'update']);
                Route::delete('karyawan/{idKaryawan}/kontrak/{id}', [KontrakKaryawanController::class, 'destroy']);
            });
    }
}
