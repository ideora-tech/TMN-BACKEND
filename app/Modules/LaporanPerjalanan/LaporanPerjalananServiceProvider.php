<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Modules\LaporanPerjalanan\Contracts\LaporanPerjalananRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LaporanPerjalananServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LaporanPerjalananRepositoryInterface::class, LaporanPerjalananRepository::class);
        $this->app->bind(LaporanPerjalananService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:trip'])
            ->group(function () {
                Route::get('trip/{idTrip}/laporan-perjalanan', [LaporanPerjalananController::class, 'showByTrip']);
                Route::post('trip/{idTrip}/laporan-perjalanan', [LaporanPerjalananController::class, 'store']);
                Route::put('laporan-perjalanan/{id}', [LaporanPerjalananController::class, 'update']);
                Route::post('laporan-perjalanan/{id}/foto', [LaporanPerjalananController::class, 'storeFoto']);
                Route::delete('laporan-perjalanan/{id}/foto/{idFoto}', [LaporanPerjalananController::class, 'destroyFoto']);
            });
    }
}
