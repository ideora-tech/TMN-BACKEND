<?php

declare(strict_types=1);

namespace App\Modules\Lokasi;

use App\Modules\Lokasi\Contracts\LokasiRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LokasiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LokasiRepositoryInterface::class, LokasiRepository::class);
        $this->app->bind(LokasiService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:lokasi|rute|penawaran|project'])
            ->group(function () {
                Route::get('lokasi', [LokasiController::class, 'index']);
                Route::get('lokasi/{id}', [LokasiController::class, 'show']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:lokasi'])
            ->group(function () {
                Route::post('lokasi', [LokasiController::class, 'store']);
                Route::match(['put', 'patch'], 'lokasi/{id}', [LokasiController::class, 'update']);
                Route::delete('lokasi/{id}', [LokasiController::class, 'destroy']);
            });
    }
}
