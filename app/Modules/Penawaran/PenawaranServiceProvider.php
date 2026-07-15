<?php

namespace App\Modules\Penawaran;
use App\Modules\Penawaran\Contracts\PenawaranRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PenawaranServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PenawaranRepositoryInterface::class, PenawaranRepository::class);
        $this->app->bind(PenawaranService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:penawaran'])
            ->group(function () {
                Route::get('penawaran/{id}/pdf', [PenawaranController::class, 'exportPdf']);

                Route::apiResource('penawaran', PenawaranController::class)
                    ->parameters(['penawaran' => 'id']);
                Route::put('penawaran/{id}/status', [PenawaranController::class, 'updateStatus']);
            });
    }
}