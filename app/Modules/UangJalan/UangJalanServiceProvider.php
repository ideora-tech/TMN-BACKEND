<?php

declare(strict_types=1);

namespace App\Modules\UangJalan;

use App\Modules\UangJalan\Contracts\UangJalanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class UangJalanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UangJalanRepositoryInterface::class, UangJalanRepository::class);
        $this->app->bind(UangJalanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:uang-jalan'])
            ->group(function () {
                Route::get('uang-jalan/opsi', [UangJalanController::class, 'opsi']);
                Route::get('uang-jalan/opsi/vendor/{idVendor}', [UangJalanController::class, 'opsiVendor']);
                Route::get('uang-jalan/{id}/riwayat', [UangJalanController::class, 'riwayat']);
                Route::apiResource('uang-jalan', UangJalanController::class)
                    ->parameters(['uang-jalan' => 'id']);
            });
    }
}
