<?php
namespace App\Modules\Rute;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RuteServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->app->bind(RuteRepositoryInterface::class, RuteRepository::class);
        $this->app->bind(RuteService::class);
    }
    public function boot(): void {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('rute', RuteController::class)
                    ->parameters(['rute' => 'id']);
            });
    }
}