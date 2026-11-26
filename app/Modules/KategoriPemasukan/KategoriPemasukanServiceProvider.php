<?php

declare(strict_types=1);

namespace App\Modules\KategoriPemasukan;

use App\Modules\KategoriPemasukan\Contracts\KategoriPemasukanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KategoriPemasukanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KategoriPemasukanRepositoryInterface::class, KategoriPemasukanRepository::class);
        $this->app->scoped(KategoriPemasukanService::class);
    }

    public function boot(): void
    {
        // Baca: dibutuhkan juga oleh form pemasukan Kas Bank & Laporan Pemasukan.
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:kategori-pemasukan|arus-kas|laporan-pemasukan,lihat'])
            ->group(function () {
                Route::get('kategori-pemasukan', [KategoriPemasukanController::class, 'index']);
                Route::get('kategori-pemasukan/opsi', [KategoriPemasukanController::class, 'opsi']);
                Route::get('kategori-pemasukan/{id}', [KategoriPemasukanController::class, 'show']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:kategori-pemasukan'])
            ->group(function () {
                Route::post('kategori-pemasukan', [KategoriPemasukanController::class, 'store']);
                Route::put('kategori-pemasukan/{id}', [KategoriPemasukanController::class, 'update']);
                Route::delete('kategori-pemasukan/{id}', [KategoriPemasukanController::class, 'destroy']);
            });
    }
}
