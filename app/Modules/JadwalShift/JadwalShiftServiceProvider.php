<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift;

use App\Modules\JadwalShift\Contracts\JadwalShiftRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class JadwalShiftServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JadwalShiftRepositoryInterface::class, JadwalShiftRepository::class);
        $this->app->bind(JadwalShiftService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::get('jadwal-shift', [JadwalShiftController::class, 'index']);
                Route::post('jadwal-shift', [JadwalShiftController::class, 'store']);
                Route::put('jadwal-shift/{id}', [JadwalShiftController::class, 'update']);
                Route::delete('jadwal-shift/{id}', [JadwalShiftController::class, 'destroy']);
            });
    }
}
