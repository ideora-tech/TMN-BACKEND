<?php

declare(strict_types=1);

namespace App\Modules\PengeluaranSupir;

use App\Modules\PengeluaranSupir\Contracts\PengeluaranSupirRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PengeluaranSupirServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PengeluaranSupirRepositoryInterface::class, PengeluaranSupirRepository::class);
        $this->app->bind(PengeluaranSupirService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::get('pengeluaran-supir/saya/opsi', [PengeluaranSupirController::class, 'opsi']);
                Route::get('pengeluaran-supir/saya', [PengeluaranSupirController::class, 'index']);
                Route::post('pengeluaran-supir/saya', [PengeluaranSupirController::class, 'store']);
                Route::get('pengeluaran-supir/saya/{id}', [PengeluaranSupirController::class, 'show']);
                Route::delete('pengeluaran-supir/saya/{id}', [PengeluaranSupirController::class, 'destroy']);
            });
    }
}
