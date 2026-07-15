<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor;

use App\Modules\ArmadaVendor\Contracts\ArmadaVendorRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ArmadaVendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ArmadaVendorRepositoryInterface::class, ArmadaVendorRepository::class);
        $this->app->bind(ArmadaVendorService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('armada-vendor', ArmadaVendorController::class)
                    ->parameters(['armada-vendor' => 'id']);
            });
    }
}
