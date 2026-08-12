<?php

declare(strict_types=1);

namespace App\Modules\PenagihanTrip;

use App\Modules\PenagihanTrip\Contracts\PenagihanTripRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PenagihanTripServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PenagihanTripRepositoryInterface::class, PenagihanTripRepository::class);
        $this->app->bind(PenagihanTripService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:faktur'])
            ->group(function () {
                Route::get('penagihan-trip', [PenagihanTripController::class, 'index']);
                Route::post('penagihan-trip/faktur', [PenagihanTripController::class, 'buatFaktur']);
            });
    }
}
