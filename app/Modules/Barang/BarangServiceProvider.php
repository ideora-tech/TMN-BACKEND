<?php

declare(strict_types=1);

namespace App\Modules\Barang;

use App\Modules\Barang\Contracts\BarangRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BarangServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BarangRepositoryInterface::class, BarangRepository::class);
        $this->app->bind(BarangService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:master-barang'])
            ->group(function () {
                Route::post('barang/{id}/pemakaian', [BarangController::class, 'pemakaian']);
                Route::post('barang/{id}/penyesuaian', [BarangController::class, 'penyesuaian']);
                Route::post('barang/buat-cepat', [BarangController::class, 'buatCepat']);
                Route::post('barang', [BarangController::class, 'store']);
                Route::put('barang/{id}', [BarangController::class, 'update']);
                Route::delete('barang/{id}', [BarangController::class, 'destroy']);
                Route::post('kategori-barang', [BarangController::class, 'storeKategori']);
                Route::put('kategori-barang/{id}', [BarangController::class, 'updateKategori']);
                Route::delete('kategori-barang/{id}', [BarangController::class, 'destroyKategori']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:master-barang|permintaan-pembelian'])
            ->group(function () {
                Route::get('barang', [BarangController::class, 'index']);
                Route::get('barang/{id}/mutasi', [BarangController::class, 'listMutasi']);
                Route::get('barang/{id}', [BarangController::class, 'show']);
                Route::get('kategori-barang', [BarangController::class, 'indexKategori']);
            });
    }
}
