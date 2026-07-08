<?php
namespace App\Modules\Dashboard;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DashboardServiceProvider extends ServiceProvider {
    public function register(): void {}
    public function boot(): void {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::get('dashboard/stats', [DashboardController::class, 'stats']);
            });
    }
}