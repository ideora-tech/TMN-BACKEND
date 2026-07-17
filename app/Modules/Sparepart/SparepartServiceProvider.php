<?php

declare(strict_types=1);

namespace App\Modules\Sparepart;

use App\Modules\Sparepart\Contracts\SparepartRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SparepartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SparepartRepositoryInterface::class, SparepartRepository::class);
        $this->app->bind(SparepartService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::post('sparepart/{id}/stok', [SparepartController::class, 'mutasiStok']);
                Route::get('sparepart/{id}/mutasi', [SparepartController::class, 'listMutasi']);
                Route::apiResource('sparepart', SparepartController::class)
                    ->parameters(['sparepart' => 'id']);
            });
    }
}
