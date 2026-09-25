<?php

declare(strict_types=1);

namespace App\Modules\Pengadaan;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PengadaanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PengadaanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:pengadaan'])
            ->group(function () {
                Route::get('pengadaan/ringkasan', [PengadaanController::class, 'ringkasan']);
            });
    }
}
