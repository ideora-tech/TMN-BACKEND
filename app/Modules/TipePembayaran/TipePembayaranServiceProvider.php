<?php

declare(strict_types=1);

namespace App\Modules\TipePembayaran;

use App\Modules\TipePembayaran\Contracts\TipePembayaranRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TipePembayaranServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TipePembayaranRepositoryInterface::class, TipePembayaranRepository::class);
        $this->app->bind(TipePembayaranService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:tipe-pembayaran'])
            ->group(function () {
                Route::get('tipe-pembayaran/opsi-aktif', [TipePembayaranController::class, 'opsiAktif']);
                Route::apiResource('tipe-pembayaran', TipePembayaranController::class)
                    ->parameters(['tipe-pembayaran' => 'id']);
            });
    }
}
