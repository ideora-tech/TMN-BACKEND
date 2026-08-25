<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiKlien;

use App\Modules\KonsolidasiKlien\Contracts\KonsolidasiKlienRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KonsolidasiKlienServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KonsolidasiKlienRepositoryInterface::class, KonsolidasiKlienRepository::class);
        $this->app->bind(KonsolidasiKlienService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:faktur'])
            ->group(function () {
                Route::get('konsolidasi-klien', [KonsolidasiKlienController::class, 'index']);
                Route::get('konsolidasi-klien/export/excel', [KonsolidasiKlienController::class, 'exportExcel']);
            });
    }
}
