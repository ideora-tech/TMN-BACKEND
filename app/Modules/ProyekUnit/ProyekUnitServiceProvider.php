<?php

declare(strict_types=1);

namespace App\Modules\ProyekUnit;

use App\Modules\ProyekUnit\Contracts\ProyekUnitRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProyekUnitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProyekUnitRepositoryInterface::class, ProyekUnitRepository::class);
        $this->app->bind(ProyekUnitService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:project'])
            ->group(function () {
                Route::get('proyek/{idProyek}/unit/opsi', [ProyekUnitController::class, 'opsi']);
                Route::get('proyek/{idProyek}/unit', [ProyekUnitController::class, 'index']);
                Route::post('proyek/{idProyek}/unit', [ProyekUnitController::class, 'store']);
                Route::delete('proyek/{idProyek}/unit', [ProyekUnitController::class, 'destroyMassal']);
                Route::delete('proyek/{idProyek}/unit/{id}', [ProyekUnitController::class, 'destroy']);
            });
    }
}
