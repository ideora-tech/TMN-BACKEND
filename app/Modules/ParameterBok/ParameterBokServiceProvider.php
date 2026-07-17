<?php

declare(strict_types=1);

namespace App\Modules\ParameterBok;

use App\Modules\ParameterBok\Contracts\ParameterBokRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ParameterBokServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ParameterBokRepositoryInterface::class, ParameterBokRepository::class);
        $this->app->bind(ParameterBokService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:parameter-bok'])
            ->group(function () {
                Route::apiResource('parameter-bok', ParameterBokController::class)
                    ->parameters(['parameter-bok' => 'id']);
            });
    }
}
