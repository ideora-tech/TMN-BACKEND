<?php

declare(strict_types=1);

namespace App\Modules\Departemen;

use App\Modules\Departemen\Contracts\DepartemenRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DepartemenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DepartemenRepositoryInterface::class, DepartemenRepository::class);
        $this->app->bind(DepartemenService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:departemen'])
            ->group(function () {
                Route::apiResource('departemen', DepartemenController::class)
                    ->parameters(['departemen' => 'id'])
                    ->only(['store', 'update', 'destroy']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:departemen|permintaan-pembelian'])
            ->group(function () {
                Route::get('departemen/tree', [DepartemenController::class, 'tree']);
                Route::apiResource('departemen', DepartemenController::class)
                    ->parameters(['departemen' => 'id'])
                    ->only(['index', 'show']);
            });
    }
}
