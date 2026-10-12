<?php

declare(strict_types=1);

namespace App\Modules\LampiranMaster;

use App\Modules\LampiranMaster\Contracts\LampiranMasterRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LampiranMasterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LampiranMasterRepositoryInterface::class, LampiranMasterRepository::class);
        $this->app->bind(LampiranMasterService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:vendor'])
            ->group(function () {
                Route::get('vendor/{id}/lampiran', [LampiranMasterController::class, 'indexVendor']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:supplier'])
            ->group(function () {
                Route::get('supplier/{id}/lampiran', [LampiranMasterController::class, 'indexSupplier']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::post('vendor/{id}/lampiran', [LampiranMasterController::class, 'storeVendor']);
                Route::delete('vendor/{id}/lampiran/{idLampiran}', [LampiranMasterController::class, 'destroyVendor']);
                Route::post('supplier/{id}/lampiran', [LampiranMasterController::class, 'storeSupplier']);
                Route::delete('supplier/{id}/lampiran/{idLampiran}', [LampiranMasterController::class, 'destroySupplier']);
            });
    }
}
