<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor;

use App\Events\ApprovalDiputuskan;
use App\Modules\InvoiceVendor\Contracts\InvoiceVendorRepositoryInterface;
use App\Modules\InvoiceVendor\Listeners\InvoiceVendorApprovalListener;
use Illuminate\Support\Facades\Event;
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
        Event::listen(ApprovalDiputuskan::class, [InvoiceVendorApprovalListener::class, 'handle']);

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:invoice-vendor'])
            ->group(function () {
                Route::get('invoice-vendor/monitoring', [InvoiceVendorController::class, 'monitoring']);
                Route::get('invoice-vendor/trip-siap-tagih', [InvoiceVendorController::class, 'tripSiapTagih']);
                Route::get('invoice-vendor', [InvoiceVendorController::class, 'index']);
                Route::get('invoice-vendor/{id}/export/pdf', [InvoiceVendorController::class, 'exportPdf']);
                Route::get('invoice-vendor/{id}', [InvoiceVendorController::class, 'show']);

                Route::middleware('role:SUPERADMIN,KEUANGAN')->group(function () {
                    Route::post('invoice-vendor', [InvoiceVendorController::class, 'store']);
                    Route::put('invoice-vendor/{id}', [InvoiceVendorController::class, 'update']);
                    Route::post('invoice-vendor/{id}/ajukan-approval', [InvoiceVendorController::class, 'ajukanApproval']);
                    Route::delete('invoice-vendor/{id}', [InvoiceVendorController::class, 'destroy']);
                });
            });
    }
}
