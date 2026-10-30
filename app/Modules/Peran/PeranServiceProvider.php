<?php

declare(strict_types=1);

namespace App\Modules\Peran;

use App\Modules\Peran\Contracts\PeranRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PeranServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PeranRepositoryInterface::class, PeranRepository::class);
        $this->app->bind(PeranService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:peran|pengguna|jabatan'])
            ->group(function () {
                Route::get('peran', [PeranController::class, 'index'])->name('peran.index');
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:peran'])
            ->group(function () {
                Route::apiResource('peran', PeranController::class)
                    ->except(['index'])
                    ->parameters(['peran' => 'id']);
            });
    }
}
