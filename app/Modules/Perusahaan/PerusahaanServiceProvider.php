<?php

declare(strict_types=1);

namespace App\Modules\Perusahaan;

use App\Modules\Perusahaan\Contracts\PerusahaanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PerusahaanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PerusahaanRepositoryInterface::class, PerusahaanRepository::class);
        $this->app->bind(PerusahaanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('perusahaan', PerusahaanController::class)
                    ->parameters(['perusahaan' => 'id']);
            });
    }
}
