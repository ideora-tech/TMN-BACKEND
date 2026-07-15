<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip;

use App\Modules\StatusTrip\Contracts\StatusTripRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class StatusTripServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StatusTripRepositoryInterface::class, StatusTripRepository::class);
        $this->app->bind(StatusTripService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:trip'])
            ->group(function () {
                Route::get('trip/{idTrip}/status', [StatusTripController::class, 'index']);
                Route::post('trip/{idTrip}/status', [StatusTripController::class, 'store']);
            });
    }
}
