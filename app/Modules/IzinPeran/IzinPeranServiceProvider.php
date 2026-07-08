<?php

declare(strict_types=1);

namespace App\Modules\IzinPeran;

use App\Modules\IzinPeran\Contracts\IzinPeranRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class IzinPeranServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IzinPeranRepositoryInterface::class, IzinPeranRepository::class);
        $this->app->bind(IzinPeranService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'role:SUPERADMIN,ADMIN,MANAGER'])
            ->group(function () {
                Route::get('izin-peran', [IzinPeranController::class, 'index']);
                Route::post('izin-peran/bulk', [IzinPeranController::class, 'bulk']);
                Route::put('izin-peran/{id}', [IzinPeranController::class, 'update']);
            });
    }
}
