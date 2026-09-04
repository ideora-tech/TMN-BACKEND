<?php

declare(strict_types=1);

namespace App\Modules\Proyek;

use App\Events\ApprovalDiputuskan;
use App\Modules\Proyek\Contracts\ProyekRepositoryInterface;
use App\Modules\Proyek\Listeners\ProyekApprovalListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProyekServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProyekRepositoryInterface::class, ProyekRepository::class);
        $this->app->bind(ProyekService::class);
    }

    public function boot(): void
    {
        Event::listen(ApprovalDiputuskan::class, [ProyekApprovalListener::class, 'handle']);

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:project'])
            ->group(function () {
                Route::get('proyek/{id}/pdf', [ProyekController::class, 'exportPdf']);
                Route::post('proyek/{id}/ajukan-approval', [ProyekController::class, 'ajukanApproval']);
                Route::post('proyek/{id}/penawaran-revisi', [ProyekController::class, 'buatPenawaranRevisi']);
                Route::post('proyek/{id}/faktur-borongan', [ProyekController::class, 'buatFakturBorongan']);

                Route::apiResource('proyek', ProyekController::class)
                    ->parameters(['proyek' => 'id']);

                Route::patch('proyek/{id}/status', [ProyekController::class, 'updateStatus']);
            });
    }
}
