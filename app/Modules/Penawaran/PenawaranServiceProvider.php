<?php

namespace App\Modules\Penawaran;
use App\Events\ApprovalDiputuskan;
use App\Modules\Penawaran\Contracts\PenawaranRepositoryInterface;
use App\Modules\Penawaran\Listeners\PenawaranApprovalListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PenawaranServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PenawaranRepositoryInterface::class, PenawaranRepository::class);
        $this->app->bind(
            \App\Modules\Penawaran\Contracts\PenawaranItemRepositoryInterface::class,
            \App\Modules\Penawaran\PenawaranItemRepository::class,
        );
        $this->app->bind(PenawaranService::class);
    }

    public function boot(): void
    {
        Event::listen(ApprovalDiputuskan::class, [PenawaranApprovalListener::class, 'handle']);

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:penawaran'])
            ->group(function () {
                Route::get('penawaran/{id}/pdf', [PenawaranController::class, 'exportPdf']);

                Route::apiResource('penawaran', PenawaranController::class)
                    ->parameters(['penawaran' => 'id']);
                Route::put('penawaran/{id}/status', [PenawaranController::class, 'updateStatus']);
                Route::post('penawaran/{id}/ajukan-approval', [PenawaranController::class, 'ajukanApproval']);
            });
    }
}