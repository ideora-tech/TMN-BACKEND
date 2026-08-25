<?php

declare(strict_types=1);

namespace App\Modules\SupirProyek;

use App\Modules\SupirProyek\Contracts\SupirProyekRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SupirProyekServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupirProyekRepositoryInterface::class, SupirProyekRepository::class);
        $this->app->bind(SupirProyekService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:penugasan'])
            ->group(function () {
                Route::get('supir-proyek', [SupirProyekController::class, 'index']);
                Route::post('supir-proyek', [SupirProyekController::class, 'store']);
                Route::delete('supir-proyek/{id}', [SupirProyekController::class, 'destroy']);
            });
    }
}
