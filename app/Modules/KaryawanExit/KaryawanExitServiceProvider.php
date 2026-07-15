<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit;

use App\Modules\KaryawanExit\Contracts\KaryawanExitRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KaryawanExitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KaryawanExitRepositoryInterface::class, KaryawanExitRepository::class);
        $this->app->bind(KaryawanExitService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:karyawan'])
            ->group(function () {
                Route::post('karyawan-exit', [KaryawanExitController::class, 'store']);
            });
    }
}
