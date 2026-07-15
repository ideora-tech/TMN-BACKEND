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
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:lokasi'])
            ->group(function () {
                Route::apiResource('lokasi', LokasiController::class)
                    ->parameters(['lokasi' => 'id']);
            });
    }
}
