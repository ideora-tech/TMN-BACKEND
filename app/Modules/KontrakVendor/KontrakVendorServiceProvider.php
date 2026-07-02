<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor;

use App\Modules\KontrakVendor\Contracts\KontrakVendorRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KontrakVendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KontrakVendorRepositoryInterface::class, KontrakVendorRepository::class);
        $this->app->bind(KontrakVendorService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                // Nested under proyek: list + create
                Route::get('proyek/{idProyek}/kontrak', [KontrakVendorController::class, 'indexByProyek']);
                Route::post('proyek/{idProyek}/kontrak', [KontrakVendorController::class, 'storeForProyek']);

                // Standalone CRUD for kontrak-vendor
                Route::get('kontrak-vendor', [KontrakVendorController::class, 'index']);
                Route::post('kontrak-vendor', [KontrakVendorController::class, 'store']);
                Route::get('kontrak-vendor/{id}', [KontrakVendorController::class, 'show']);
                Route::put('kontrak-vendor/{id}', [KontrakVendorController::class, 'update']);
                Route::delete('kontrak-vendor/{id}', [KontrakVendorController::class, 'destroy']);
            });
    }
}
