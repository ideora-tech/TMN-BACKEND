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
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:pembelian-sparepart'])
            ->group(function () {
                Route::get('pembelian-sparepart/laporan', [PembelianSparepartController::class, 'laporan']);
                Route::patch('pembelian-sparepart/{id}/approve-manager', [PembelianSparepartController::class, 'approveManager'])
                    ->middleware('role:SUPERADMIN,ADMIN,MANAGER');
                Route::patch('pembelian-sparepart/{id}/approve-finance', [PembelianSparepartController::class, 'approveFinance'])
                    ->middleware('role:SUPERADMIN,ADMIN,KEUANGAN');
                Route::patch('pembelian-sparepart/{id}/tolak', [PembelianSparepartController::class, 'tolak'])
                    ->middleware('role:SUPERADMIN,ADMIN,MANAGER,KEUANGAN');
                Route::patch('pembelian-sparepart/{id}/realisasi', [PembelianSparepartController::class, 'realisasi'])
                    ->middleware('role:SUPERADMIN,ADMIN,DISPATCHER');
                Route::patch('pembelian-sparepart/{id}/lunas', [PembelianSparepartController::class, 'lunas'])
                    ->middleware('role:SUPERADMIN,ADMIN,KEUANGAN');
                Route::post('pembelian-sparepart/{id}/bukti', [PembelianSparepartController::class, 'tambahBukti']);
                Route::delete('pembelian-sparepart/{id}/bukti/{idBukti}', [PembelianSparepartController::class, 'hapusBukti']);
                Route::apiResource('pembelian-sparepart', PembelianSparepartController::class)
                    ->parameters(['pembelian-sparepart' => 'id']);
            });
    }
}
