<?php

declare(strict_types=1);

namespace App\Modules\Shift;

use App\Modules\Shift\Contracts\ShiftRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ShiftServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShiftRepositoryInterface::class, ShiftRepository::class);
        $this->app->bind(ShiftService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:shift|jadwal-shift-supir'])
            ->group(function () {
                Route::get('shift', [ShiftController::class, 'index']);
                Route::get('shift/{id}', [ShiftController::class, 'show']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:shift'])
            ->group(function () {
                Route::post('shift', [ShiftController::class, 'store']);
                Route::put('shift/{id}', [ShiftController::class, 'update']);
                Route::patch('shift/{id}', [ShiftController::class, 'update']);
                Route::delete('shift/{id}', [ShiftController::class, 'destroy']);
            });
    }
}
