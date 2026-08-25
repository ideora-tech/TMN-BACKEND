<?php

declare(strict_types=1);

namespace App\Modules\WajahReferensi;

use App\Modules\WajahReferensi\Contracts\WajahReferensiRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WajahReferensiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WajahReferensiRepositoryInterface::class, WajahReferensiRepository::class);
        $this->app->bind(WajahReferensiService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::get('wajah/saya', [WajahReferensiController::class, 'saya']);
                Route::post('wajah/saya', [WajahReferensiController::class, 'daftar']);
            });
    }
}
