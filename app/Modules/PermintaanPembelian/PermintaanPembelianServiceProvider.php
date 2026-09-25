<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian;

use App\Events\ApprovalDiputuskan;
use App\Modules\PembelianSparepart\Events\PembelianSparepartDirealisasi;
use App\Modules\PermintaanPembelian\Contracts\PermintaanPembelianRepositoryInterface;
use App\Modules\PermintaanPembelian\Listeners\PermintaanPembelianApprovalListener;
use App\Modules\PermintaanPembelian\Listeners\PermintaanPembelianDariSparepartListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PermintaanPembelianServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PermintaanPembelianRepositoryInterface::class, PermintaanPembelianRepository::class);
        $this->app->bind(PermintaanPembelianService::class);
    }

    public function boot(): void
    {
        Event::listen(ApprovalDiputuskan::class, [PermintaanPembelianApprovalListener::class, 'handle']);
        Event::listen(PembelianSparepartDirealisasi::class, [PermintaanPembelianDariSparepartListener::class, 'handle']);

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:permintaan-pembelian'])
            ->group(function () {
                Route::get('permintaan-pembelian/laporan', [PermintaanPembelianController::class, 'laporan']);
                Route::get('permintaan-pembelian/laporan/export/excel', [PermintaanPembelianController::class, 'exportLaporanExcel']);
                Route::get('permintaan-pembelian/laporan/export/pdf', [PermintaanPembelianController::class, 'exportLaporanPdf']);
                Route::get('permintaan-pembelian/{id}/pengajuan', [PermintaanPembelianController::class, 'infoPengajuan']);
                Route::patch('permintaan-pembelian/{id}/realisasi-sparepart', [PermintaanPembelianController::class, 'realisasiSparepart']);
                Route::patch('permintaan-pembelian/{id}/proses', [PermintaanPembelianController::class, 'proses']);
                Route::patch('permintaan-pembelian/{id}/dibeli', [PermintaanPembelianController::class, 'dibeli']);
                Route::patch('permintaan-pembelian/{id}/terima', [PermintaanPembelianController::class, 'terima']);
                Route::patch('permintaan-pembelian/{id}/batal', [PermintaanPembelianController::class, 'batal']);
                Route::post('permintaan-pembelian/{id}/bukti', [PermintaanPembelianController::class, 'tambahBukti']);
                Route::delete('permintaan-pembelian/{id}/bukti/{idBukti}', [PermintaanPembelianController::class, 'hapusBukti']);
                Route::apiResource('permintaan-pembelian', PermintaanPembelianController::class)
                    ->parameters(['permintaan-pembelian' => 'id'])
                    ->only(['index', 'show', 'store', 'update', 'destroy']);
            });
    }
}
