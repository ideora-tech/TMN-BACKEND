<?php

declare(strict_types=1);

namespace App\Modules\Vendor;

use App\Modules\Vendor\Contracts\VendorRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class VendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VendorRepositoryInterface::class, VendorRepository::class);
        $this->app->bind(VendorService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:vendor'])
            ->group(function () {
                Route::apiResource('vendor', VendorController::class)
                    ->parameters(['vendor' => 'id']);
            });
    }
}
