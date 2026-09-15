<?php
declare(strict_types=1);

namespace App\Modules\Supplier;

use App\Modules\Supplier\Contracts\SupplierRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SupplierServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupplierRepositoryInterface::class, SupplierRepository::class);
        $this->app->bind(SupplierService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:supplier|perawatan-armada|pembelian-sparepart'])
            ->group(function () {
                Route::get('supplier', [SupplierController::class, 'index']);
                Route::get('supplier/{id}', [SupplierController::class, 'show']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:supplier'])
            ->group(function () {
                Route::post('supplier', [SupplierController::class, 'store']);
                Route::put('supplier/{id}', [SupplierController::class, 'update']);
                Route::patch('supplier/{id}', [SupplierController::class, 'update']);
                Route::delete('supplier/{id}', [SupplierController::class, 'destroy']);
            });
    }
}
