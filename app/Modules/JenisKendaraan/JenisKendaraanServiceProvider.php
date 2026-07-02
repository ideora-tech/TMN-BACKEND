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
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('jenis-kendaraan', JenisKendaraanController::class)
                    ->parameters(['jenis-kendaraan' => 'id']);
            });
    }
}
