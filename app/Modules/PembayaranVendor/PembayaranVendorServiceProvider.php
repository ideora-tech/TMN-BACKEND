<?php

declare(strict_types=1);

namespace App\Modules\PembayaranVendor;

use App\Modules\PembayaranVendor\Contracts\PembayaranVendorRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PembayaranVendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PembayaranVendorRepositoryInterface::class, PembayaranVendorRepository::class);
        $this->app->bind(PembayaranVendorService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:invoice-vendor'])
            ->group(function () {
                Route::get('invoice-vendor/{idInvoice}/pembayaran', [PembayaranVendorController::class, 'indexByInvoice']);

                Route::middleware('role:SUPERADMIN,KEUANGAN')->group(function () {
                    Route::post('invoice-vendor/{idInvoice}/pembayaran', [PembayaranVendorController::class, 'store']);
                    Route::delete('invoice-vendor/{idInvoice}/pembayaran/{id}', [PembayaranVendorController::class, 'destroy']);
                });
            });
    }
}
