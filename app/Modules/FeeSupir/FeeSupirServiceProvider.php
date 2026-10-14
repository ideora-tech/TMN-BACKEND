<?php

declare(strict_types=1);

namespace App\Modules\FeeSupir;

use App\Modules\FeeSupir\Contracts\FeeSupirRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FeeSupirServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FeeSupirRepositoryInterface::class, FeeSupirRepository::class);
        $this->app->bind(FeeSupirService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:supir'])
            ->group(function () {
                Route::get('supir/me/fee', [FeeSupirController::class, 'feeSaya']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:penugasan'])
            ->group(function () {
                Route::get('fee-supir/urutan-trip', [FeeSupirController::class, 'urutanTrip']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:fee-supir'])
            ->group(function () {
                Route::get('fee-supir/ringkasan', [FeeSupirController::class, 'ringkasan']);
                Route::get('fee-supir/rekap', [FeeSupirController::class, 'rekap']);
                Route::get('fee-supir/export/excel', [FeeSupirController::class, 'exportExcel']);
                Route::get('fee-supir', [FeeSupirController::class, 'index']);
            });
    }
}
