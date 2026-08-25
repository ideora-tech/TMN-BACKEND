<?php
declare(strict_types=1);

namespace App\Modules\PembelianSparepart;

use App\Modules\PembelianSparepart\Contracts\PembelianSparepartRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PembelianSparepartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PembelianSparepartRepositoryInterface::class, PembelianSparepartRepository::class);
        $this->app->bind(PembelianSparepartService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:pembelian-sparepart'])
            ->group(function () {
                Route::get('pembelian-sparepart/laporan', [PembelianSparepartController::class, 'laporan']);
                Route::get('pembelian-sparepart/laporan/export/excel', [PembelianSparepartController::class, 'exportLaporanExcel']);
                Route::get('pembelian-sparepart/laporan/export/pdf', [PembelianSparepartController::class, 'exportLaporanPdf']);
                Route::patch('pembelian-sparepart/{id}/realisasi', [PembelianSparepartController::class, 'realisasi'])
                    ->middleware('role:SUPERADMIN,ADMIN,DISPATCHER');
                Route::post('pembelian-sparepart/{id}/bukti', [PembelianSparepartController::class, 'tambahBukti']);
                Route::delete('pembelian-sparepart/{id}/bukti/{idBukti}', [PembelianSparepartController::class, 'hapusBukti']);
                Route::apiResource('pembelian-sparepart', PembelianSparepartController::class)
                    ->parameters(['pembelian-sparepart' => 'id']);
            });
    }
}
