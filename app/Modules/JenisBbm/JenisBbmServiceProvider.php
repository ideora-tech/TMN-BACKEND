<?php

declare(strict_types=1);

namespace App\Modules\JenisBbm;

use App\Modules\JenisBbm\Contracts\JenisBbmRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class JenisBbmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JenisBbmRepositoryInterface::class, JenisBbmRepository::class);
        $this->app->bind(JenisBbmService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:jenis-bbm'])
            ->group(function () {
                // Route riwayat harga sebelum apiResource agar tidak tertimpa route show/destroy.
                Route::get('jenis-bbm/{id}/harga', [JenisBbmController::class, 'riwayatHarga']);
                Route::post('jenis-bbm/{id}/harga', [JenisBbmController::class, 'tambahHarga']);

                Route::apiResource('jenis-bbm', JenisBbmController::class)
                    ->parameters(['jenis-bbm' => 'id']);
            });
    }
}
