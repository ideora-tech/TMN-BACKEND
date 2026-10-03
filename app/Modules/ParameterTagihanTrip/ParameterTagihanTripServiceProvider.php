<?php

declare(strict_types=1);

namespace App\Modules\ParameterTagihanTrip;

use App\Modules\ParameterTagihanTrip\Contracts\ParameterTagihanTripRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ParameterTagihanTripServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ParameterTagihanTripRepositoryInterface::class, ParameterTagihanTripRepository::class);
        $this->app->bind(ParameterTagihanTripService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:trip|faktur'])
            ->group(function () {
                Route::get('trip/{id}/parameter-tagihan', [ParameterTagihanTripController::class, 'show']);
                Route::put('trip/{id}/parameter-tagihan', [ParameterTagihanTripController::class, 'update']);
            });
    }
}
