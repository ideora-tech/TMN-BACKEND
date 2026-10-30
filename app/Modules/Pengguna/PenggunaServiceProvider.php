<?php

declare(strict_types=1);

namespace App\Modules\Pengguna;

use App\Modules\Pengguna\Contracts\PenggunaRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PenggunaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PenggunaRepositoryInterface::class, PenggunaRepository::class);
        $this->app->bind(PenggunaService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:pengguna|konfigurasi-approval|supir-vendor'])
            ->group(function () {
                Route::get('pengguna', [PenggunaController::class, 'index'])->name('pengguna.index');
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:pengguna'])
            ->group(function () {
                Route::get('pengguna/opsi-supir', [PenggunaController::class, 'opsiSupir']);

                Route::apiResource('pengguna', PenggunaController::class)
                    ->except(['index'])
                    ->parameters(['pengguna' => 'id']);

                Route::put('pengguna/{id}/change-password', [PenggunaController::class, 'changePassword'])
                    ->name('pengguna.change-password');
            });
    }
}
