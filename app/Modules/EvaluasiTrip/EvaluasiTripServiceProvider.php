<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip;

use App\Modules\EvaluasiTrip\Contracts\EvaluasiTripRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class EvaluasiTripServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EvaluasiTripRepositoryInterface::class, EvaluasiTripRepository::class);
        $this->app->bind(EvaluasiTripService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:trip'])
            ->group(function () {
                Route::get('penugasan/{idPenugasan}/evaluasi', [EvaluasiTripController::class, 'showByPenugasan']);
                Route::post('penugasan/{idPenugasan}/evaluasi', [EvaluasiTripController::class, 'storeByPenugasan']);
                Route::put('evaluasi/{id}', [EvaluasiTripController::class, 'update']);
            });
    }
}
