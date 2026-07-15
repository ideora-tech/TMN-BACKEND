<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TripServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TripRepositoryInterface::class, TripRepository::class);
        $this->app->bind(TripService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:trip'])
            ->group(function () {
                Route::get('trip', [TripController::class, 'index']);
                Route::post('trip', [TripController::class, 'store']);
                Route::get('trip/{id}', [TripController::class, 'show']);
                Route::delete('trip/{id}', [TripController::class, 'destroy']);

                Route::post('trip/{id}/checkin', [TripController::class, 'checkin']);
                Route::post('trip/{id}/checkout', [TripController::class, 'checkout']);
                Route::post('trip/{id}/batalkan', [TripController::class, 'batalkan']);
                Route::get('trip/{id}/rekap-biaya', [TripController::class, 'rekapBiaya']);
            });
    }
}
