<?php

declare(strict_types=1);

namespace App\Modules\PaketLangganan;

use App\Modules\PaketLangganan\Contracts\PaketLanggananRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PaketLanggananServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaketLanggananRepositoryInterface::class, PaketLanggananRepository::class);
        $this->app->bind(PaketLanggananService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('paket-langganan', PaketLanggananController::class)
                    ->parameters(['paket-langganan' => 'id']);
            });
    }
}
