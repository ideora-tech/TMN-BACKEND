<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute;

use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProyekRuteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProyekRuteRepositoryInterface::class, ProyekRuteRepository::class);
        $this->app->bind(ProyekRuteService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:project'])
            ->group(function () {
                Route::get('proyek/{idProyek}/rute', [ProyekRuteController::class, 'index']);
                Route::post('proyek/{idProyek}/rute', [ProyekRuteController::class, 'store']);
                Route::put('proyek/{idProyek}/rute/{id}', [ProyekRuteController::class, 'update']);
                Route::delete('proyek/{idProyek}/rute/{id}', [ProyekRuteController::class, 'destroy']);
            });
    }
}
