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
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('peran', PeranController::class)
                    ->parameters(['peran' => 'id']);
            });
    }
}
