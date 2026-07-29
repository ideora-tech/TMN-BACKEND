<?php

declare(strict_types=1);

namespace App\Modules\RekeningVendor;

use App\Modules\RekeningVendor\Contracts\RekeningVendorRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RekeningVendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RekeningVendorRepositoryInterface::class, RekeningVendorRepository::class);
        $this->app->bind(RekeningVendorService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:vendor'])
            ->group(function () {
                Route::get('vendor/{idVendor}/rekening', [RekeningVendorController::class, 'indexByVendor']);
                Route::post('vendor/{idVendor}/rekening', [RekeningVendorController::class, 'store']);
                Route::put('vendor/{idVendor}/rekening/{id}', [RekeningVendorController::class, 'update']);
                Route::delete('vendor/{idVendor}/rekening/{id}', [RekeningVendorController::class, 'destroy']);
            });
    }
}
