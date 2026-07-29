<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor;

use App\Modules\InvoiceVendor\Contracts\InvoiceVendorRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class InvoiceVendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InvoiceVendorRepositoryInterface::class, InvoiceVendorRepository::class);
        $this->app->bind(InvoiceVendorService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:invoice-vendor'])
            ->group(function () {
                Route::get('invoice-vendor/monitoring', [InvoiceVendorController::class, 'monitoring']);
                Route::get('invoice-vendor', [InvoiceVendorController::class, 'index']);
                Route::get('invoice-vendor/{id}', [InvoiceVendorController::class, 'show']);

                Route::middleware('role:SUPERADMIN,KEUANGAN')->group(function () {
                    Route::post('invoice-vendor', [InvoiceVendorController::class, 'store']);
                    Route::put('invoice-vendor/{id}', [InvoiceVendorController::class, 'update']);
                    Route::patch('invoice-vendor/{id}/verifikasi', [InvoiceVendorController::class, 'verifikasi']);
                    Route::delete('invoice-vendor/{id}', [InvoiceVendorController::class, 'destroy']);
                });
            });
    }
}
