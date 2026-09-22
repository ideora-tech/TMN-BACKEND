<?php

declare(strict_types=1);

namespace App\Modules\KetersediaanVendor;

use App\Modules\KetersediaanVendor\Contracts\KetersediaanVendorRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KetersediaanVendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KetersediaanVendorRepositoryInterface::class, KetersediaanVendorRepository::class);
        $this->app->bind(KetersediaanVendorService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:ketersediaan-vendor'])
            ->group(function () {
                Route::get('ketersediaan-vendor/export/excel', [KetersediaanVendorController::class, 'exportExcel']);
                Route::get('ketersediaan-vendor', [KetersediaanVendorController::class, 'index']);
                Route::get('ketersediaan-vendor/{sumber}/{id}', [KetersediaanVendorController::class, 'show'])
                    ->whereIn('sumber', KetersediaanVendorService::SUMBER_VALID);
            });
    }
}
