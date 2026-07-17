<?php

declare(strict_types=1);

namespace App\Modules\TarifRute;

use App\Modules\TarifRute\Contracts\TarifRuteRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TarifRuteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TarifRuteRepositoryInterface::class, TarifRuteRepository::class);
        $this->app->bind(TarifRuteService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:tarif-rute'])
            ->group(function () {
                // Route statis harus SEBELUM apiResource agar tidak tertangkap {id}.
                Route::get('tarif-rute/resolusi', [TarifRuteController::class, 'resolusi']);
                Route::get('tarif-rute/estimasi-bok', [TarifRuteController::class, 'estimasiBok']);

                Route::apiResource('tarif-rute', TarifRuteController::class)
                    ->parameters(['tarif-rute' => 'id']);
            });
    }
}
