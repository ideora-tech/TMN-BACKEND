<?php

declare(strict_types=1);

namespace App\Modules\KategoriPengajuan;

use App\Modules\KategoriPengajuan\Contracts\KategoriPengajuanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KategoriPengajuanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KategoriPengajuanRepositoryInterface::class, KategoriPengajuanRepository::class);
        $this->app->scoped(KategoriPengajuanService::class);
    }

    public function boot(): void
    {
        // Baca: dibutuhkan juga oleh form pengajuan (Proses Pembayaran / Kas Bank) & Laporan Pengeluaran.
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:kategori-pengajuan|proses-pembayaran|arus-kas|laporan-pengeluaran,lihat'])
            ->group(function () {
                Route::get('kategori-pengajuan', [KategoriPengajuanController::class, 'index']);
                Route::get('kategori-pengajuan/opsi', [KategoriPengajuanController::class, 'opsi']);
                Route::get('kategori-pengajuan/{id}', [KategoriPengajuanController::class, 'show']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:kategori-pengajuan'])
            ->group(function () {
                Route::post('kategori-pengajuan', [KategoriPengajuanController::class, 'store']);
                Route::put('kategori-pengajuan/{id}', [KategoriPengajuanController::class, 'update']);
                Route::delete('kategori-pengajuan/{id}', [KategoriPengajuanController::class, 'destroy']);
            });
    }
}
