<?php

declare(strict_types=1);

namespace App\Modules\Klien;

use App\Modules\Klien\Contracts\KlienRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KlienServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KlienRepositoryInterface::class, KlienRepository::class);
        $this->app->bind(KlienService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('klien', KlienController::class)
                    ->parameters(['klien' => 'id']);
            });
    }
}
