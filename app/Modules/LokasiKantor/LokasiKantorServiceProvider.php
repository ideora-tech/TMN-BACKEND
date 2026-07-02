<?php

declare(strict_types=1);

namespace App\Modules\LokasiKantor;

use App\Modules\LokasiKantor\Contracts\LokasiKantorRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LokasiKantorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LokasiKantorRepositoryInterface::class, LokasiKantorRepository::class);
        $this->app->bind(LokasiKantorService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('lokasi-kantor', LokasiKantorController::class)
                    ->parameters(['lokasi-kantor' => 'id']);
            });
    }
}
