<?php

declare(strict_types=1);

namespace App\Modules\PermintaanVendor;

use App\Events\ApprovalDiputuskan;
use App\Modules\PermintaanVendor\Contracts\PermintaanVendorRepositoryInterface;
use App\Modules\PermintaanVendor\Listeners\PermintaanVendorApprovalListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PermintaanVendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PermintaanVendorRepositoryInterface::class, PermintaanVendorRepository::class);
        $this->app->bind(PermintaanVendorService::class);
    }

    public function boot(): void
    {
        Event::listen(ApprovalDiputuskan::class, [PermintaanVendorApprovalListener::class, 'handle']);

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:permintaan-vendor|kontrak-vendor'])
            ->group(function () {
                Route::get('permintaan-vendor', [PermintaanVendorController::class, 'index']);
                Route::get('permintaan-vendor/{id}', [PermintaanVendorController::class, 'show']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:permintaan-vendor'])
            ->group(function () {
                Route::post('permintaan-vendor', [PermintaanVendorController::class, 'store']);
                Route::post('permintaan-vendor/{id}/ajukan-approval', [PermintaanVendorController::class, 'ajukanApproval']);
                Route::put('permintaan-vendor/{id}', [PermintaanVendorController::class, 'update']);
                Route::delete('permintaan-vendor/{id}', [PermintaanVendorController::class, 'destroy']);
            });
    }
}
