<?php

declare(strict_types=1);

namespace App\Modules\Armada;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ArmadaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ArmadaRepositoryInterface::class, ArmadaRepository::class);
        $this->app->bind(ArmadaService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:armada'])
            ->group(function () {
                Route::apiResource('armada', ArmadaController::class)
                    ->parameters(['armada' => 'id']);
            });
    }
}
