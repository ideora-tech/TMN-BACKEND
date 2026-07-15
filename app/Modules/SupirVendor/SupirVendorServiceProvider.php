<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor;

use App\Modules\SupirVendor\Contracts\SupirVendorRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SupirVendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupirVendorRepositoryInterface::class, SupirVendorRepository::class);
        $this->app->bind(SupirVendorService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:supir-vendor'])
            ->group(function () {
                Route::apiResource('supir-vendor', SupirVendorController::class)
                    ->parameters(['supir-vendor' => 'id']);
            });
    }
}
