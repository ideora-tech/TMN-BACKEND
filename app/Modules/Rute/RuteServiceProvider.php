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
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:rute'])
            ->group(function () {
                Route::get('rute/estimasi-bok', [RuteController::class, 'estimasiBok']);
                Route::get('rute/import/template', [RuteController::class, 'downloadTemplate']);
                Route::post('rute/import', [RuteController::class, 'import']);
                Route::get('rute/export/excel', [RuteController::class, 'exportExcel']);

                Route::apiResource('rute', RuteController::class)
                    ->parameters(['rute' => 'id']);
            });
    }
}