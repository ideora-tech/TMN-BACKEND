<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada;

use App\Modules\DokumenArmada\Contracts\DokumenArmadaRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DokumenArmadaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DokumenArmadaRepositoryInterface::class, DokumenArmadaRepository::class);
        $this->app->bind(DokumenArmadaService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:armada'])
            ->group(function () {
                // Nested under armada
                Route::get('armada/{idArmada}/dokumen', [DokumenArmadaController::class, 'indexByArmada']);
                Route::post('armada/{idArmada}/dokumen', [DokumenArmadaController::class, 'store']);
                Route::put('armada/{idArmada}/dokumen/{id}', [DokumenArmadaController::class, 'update']);
                Route::delete('armada/{idArmada}/dokumen/{id}', [DokumenArmadaController::class, 'destroy']);

                // Standalone expiring query
                Route::get('dokumen-armada/expiring', [DokumenArmadaController::class, 'expiring']);
            });
    }
}
