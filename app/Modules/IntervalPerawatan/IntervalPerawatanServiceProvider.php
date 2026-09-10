<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan;

use App\Modules\IntervalPerawatan\Contracts\IntervalPerawatanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class IntervalPerawatanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IntervalPerawatanRepositoryInterface::class, IntervalPerawatanRepository::class);
        $this->app->bind(IntervalPerawatanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:armada|perawatan-armada'])
            ->group(function () {
                Route::get('interval-perawatan', [IntervalPerawatanController::class, 'index']);
                Route::get('interval-perawatan/{id}', [IntervalPerawatanController::class, 'show']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:armada'])
            ->group(function () {
                Route::post('interval-perawatan', [IntervalPerawatanController::class, 'store']);
                Route::put('interval-perawatan/{id}', [IntervalPerawatanController::class, 'update']);
                Route::patch('interval-perawatan/{id}', [IntervalPerawatanController::class, 'update']);
                Route::delete('interval-perawatan/{id}', [IntervalPerawatanController::class, 'destroy']);
            });
    }
}
