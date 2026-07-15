<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Modules\PerawatanArmada\Contracts\PerawatanArmadaRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PerawatanArmadaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PerawatanArmadaRepositoryInterface::class, PerawatanArmadaRepository::class);
        $this->app->bind(PerawatanArmadaService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:armada'])
            ->group(function () {
                Route::get('armada/{idArmada}/perawatan', [PerawatanArmadaController::class, 'indexByArmada']);
                Route::get('armada/{idArmada}/perawatan/{id}', [PerawatanArmadaController::class, 'show']);
                Route::post('armada/{idArmada}/perawatan', [PerawatanArmadaController::class, 'store']);
                Route::put('armada/{idArmada}/perawatan/{id}', [PerawatanArmadaController::class, 'update']);
                Route::patch('armada/{idArmada}/perawatan/{id}', [PerawatanArmadaController::class, 'update']);
                Route::delete('armada/{idArmada}/perawatan/{id}', [PerawatanArmadaController::class, 'destroy']);
            });
    }
}
