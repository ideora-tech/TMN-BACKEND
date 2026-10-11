<?php

declare(strict_types=1);

namespace App\Modules\KasKecil;

use App\Modules\KasKecil\Contracts\KasKecilRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KasKecilServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KasKecilRepositoryInterface::class, KasKecilRepository::class);
        $this->app->bind(KasKecilService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:kas-kecil'])
            ->group(function () {
                Route::get('kas-kecil/ringkasan', [KasKecilController::class, 'ringkasan']);
                Route::get('kas-kecil/buku', [KasKecilController::class, 'buku']);
                Route::get('kas-kecil/buku/export/excel', [KasKecilController::class, 'exportBuku']);

                Route::get('kas-kecil/transaksi/{id}', [KasKecilController::class, 'showTransaksi']);
                Route::post('kas-kecil/transaksi', [KasKecilController::class, 'storeTransaksi']);
                Route::put('kas-kecil/transaksi/{id}', [KasKecilController::class, 'updateTransaksi']);
                Route::delete('kas-kecil/transaksi/{id}', [KasKecilController::class, 'destroyTransaksi']);

                Route::middleware('role:' . implode(',', KasKecilService::PERAN_SALDO_AWAL))->group(function () {
                    Route::post('kas-kecil/saldo-awal', [KasKecilController::class, 'storeSaldoAwal']);
                    Route::delete('kas-kecil/saldo-awal', [KasKecilController::class, 'destroySaldoAwal']);
                });

                Route::get('kas-kecil/pengisian', [KasKecilController::class, 'indexPengisian']);
                Route::post('kas-kecil/pengisian', [KasKecilController::class, 'storePengisian']);
                Route::get('kas-kecil/pengisian/{id}', [KasKecilController::class, 'showPengisian']);
                Route::get('kas-kecil/pengisian/{id}/riwayat', [KasKecilController::class, 'riwayatPengisian']);
                Route::put('kas-kecil/pengisian/{id}', [KasKecilController::class, 'updatePengisian']);
                Route::delete('kas-kecil/pengisian/{id}', [KasKecilController::class, 'destroyPengisian']);
            });
    }
}
