<?php

declare(strict_types=1);

namespace App\Modules\TipePermintaan;

use App\Modules\TipePermintaan\Contracts\TipePermintaanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TipePermintaanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TipePermintaanRepositoryInterface::class, TipePermintaanRepository::class);
        $this->app->bind(TipePermintaanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:tipe-permintaan|judul-permintaan'])
            ->group(function () {
                Route::get('tipe-permintaan/opsi-aktif', [TipePermintaanController::class, 'opsiAktif']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:tipe-permintaan'])
            ->group(function () {
                Route::apiResource('tipe-permintaan', TipePermintaanController::class)
                    ->parameters(['tipe-permintaan' => 'id']);
            });
    }
}
