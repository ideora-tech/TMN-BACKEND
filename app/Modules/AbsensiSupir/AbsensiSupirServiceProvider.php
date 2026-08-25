<?php

declare(strict_types=1);

namespace App\Modules\AbsensiSupir;

use App\Modules\AbsensiSupir\Contracts\AbsensiSupirRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AbsensiSupirServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AbsensiSupirRepositoryInterface::class, AbsensiSupirRepository::class);
        $this->app->bind(AbsensiSupirService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:trip'])
            ->group(function () {
                Route::get('absensi-supir/hari-ini-saya', [AbsensiSupirController::class, 'hariIniSaya']);
                Route::post('absensi-supir', [AbsensiSupirController::class, 'store']);
            });
    }
}
