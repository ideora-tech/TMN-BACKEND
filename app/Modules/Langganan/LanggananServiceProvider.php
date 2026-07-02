<?php

declare(strict_types=1);

namespace App\Modules\Langganan;

use App\Modules\Langganan\Contracts\LanggananRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LanggananServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LanggananRepositoryInterface::class, LanggananRepository::class);
        $this->app->bind(LanggananService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('langganan', LanggananController::class)
                    ->parameters(['langganan' => 'id']);
            });
    }
}
