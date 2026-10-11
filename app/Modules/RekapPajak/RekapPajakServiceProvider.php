<?php

declare(strict_types=1);

namespace App\Modules\RekapPajak;

use App\Modules\RekapPajak\Contracts\RekapPajakRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RekapPajakServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RekapPajakRepositoryInterface::class, RekapPajakRepository::class);
        $this->app->bind(RekapPajakService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:rekap-pajak'])
            ->group(function () {
                Route::get('rekap-pajak', [RekapPajakController::class, 'index']);
                Route::get('rekap-pajak/rincian', [RekapPajakController::class, 'rincian']);
                Route::get('rekap-pajak/export/excel', [RekapPajakController::class, 'exportExcel']);
            });
    }
}
