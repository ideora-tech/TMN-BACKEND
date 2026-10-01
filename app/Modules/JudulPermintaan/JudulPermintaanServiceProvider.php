<?php

declare(strict_types=1);

namespace App\Modules\JudulPermintaan;

use App\Modules\JudulPermintaan\Contracts\JudulPermintaanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class JudulPermintaanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JudulPermintaanRepositoryInterface::class, JudulPermintaanRepository::class);
        $this->app->bind(JudulPermintaanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:judul-permintaan|permintaan-pembelian'])
            ->group(function () {
                Route::get('judul-permintaan/opsi-aktif', [JudulPermintaanController::class, 'opsiAktif']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:judul-permintaan'])
            ->group(function () {
                Route::apiResource('judul-permintaan', JudulPermintaanController::class)
                    ->parameters(['judul-permintaan' => 'id']);
            });
    }
}
