<?php

declare(strict_types=1);

namespace App\Modules\DokumenVendor;

use App\Modules\DokumenVendor\Contracts\DokumenVendorRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DokumenVendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DokumenVendorRepositoryInterface::class, DokumenVendorRepository::class);
        $this->app->bind(DokumenVendorService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:vendor'])
            ->group(function () {
                // Nested under vendor
                Route::get('vendor/{idVendor}/dokumen', [DokumenVendorController::class, 'indexByVendor']);
                Route::post('vendor/{idVendor}/dokumen', [DokumenVendorController::class, 'store']);
                Route::put('vendor/{idVendor}/dokumen/{id}', [DokumenVendorController::class, 'update']);
                Route::delete('vendor/{idVendor}/dokumen/{id}', [DokumenVendorController::class, 'destroy']);

                // Standalone expiring query
                Route::get('dokumen-vendor/expiring', [DokumenVendorController::class, 'expiring']);
            });
    }
}
