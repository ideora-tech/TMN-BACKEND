<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiVendor;

use App\Modules\KonsolidasiVendor\Contracts\KonsolidasiVendorRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KonsolidasiVendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KonsolidasiVendorRepositoryInterface::class, KonsolidasiVendorRepository::class);
        $this->app->bind(KonsolidasiVendorService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:invoice-vendor'])
            ->group(function () {
                Route::get('konsolidasi-vendor', [KonsolidasiVendorController::class, 'index']);
                Route::get('konsolidasi-vendor/export/excel', [KonsolidasiVendorController::class, 'exportExcel']);
            });
    }
}
