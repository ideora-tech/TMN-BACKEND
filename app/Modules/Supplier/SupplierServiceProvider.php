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
            ->middleware(['api', 'auth:sanctum', 'izin:supplier'])
            ->group(function () {
                Route::apiResource('supplier', SupplierController::class)
                    ->parameters(['supplier' => 'id']);
            });
    }
}
