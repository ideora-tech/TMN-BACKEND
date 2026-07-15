<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KaryawanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KaryawanRepositoryInterface::class, KaryawanRepository::class);
        $this->app->bind(KaryawanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:karyawan'])
            ->group(function () {
                Route::get('karyawan/{id}/exit-history', [KaryawanController::class, 'exitHistory']);
                Route::apiResource('karyawan', KaryawanController::class)
                    ->parameters(['karyawan' => 'id']);
            });
    }
}
