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
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('shift', ShiftController::class)
                    ->parameters(['shift' => 'id']);
            });
    }
}
