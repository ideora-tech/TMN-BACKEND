<?php

declare(strict_types=1);

namespace App\Modules\TokenPerangkat;

use App\Modules\TokenPerangkat\Contracts\TokenPerangkatRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TokenPerangkatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TokenPerangkatRepositoryInterface::class, TokenPerangkatRepository::class);
        $this->app->bind(TokenPerangkatService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::post('token-perangkat', [TokenPerangkatController::class, 'store']);
                Route::delete('token-perangkat', [TokenPerangkatController::class, 'destroy']);
            });
    }
}
