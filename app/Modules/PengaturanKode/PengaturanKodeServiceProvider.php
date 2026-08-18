<?php

declare(strict_types=1);

namespace App\Modules\PengaturanKode;

use App\Modules\PengaturanKode\Contracts\PengaturanKodeRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PengaturanKodeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PengaturanKodeRepositoryInterface::class, PengaturanKodeRepository::class);
        $this->app->bind(PengaturanKodeService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'role:SUPERADMIN,ADMIN'])
            ->group(function () {
                Route::get('pengaturan-kode', [PengaturanKodeController::class, 'index']);
                Route::put('pengaturan-kode/{entitas}', [PengaturanKodeController::class, 'update']);
            });
    }
}
