<?php

declare(strict_types=1);

namespace App\Modules\Sparepart;

use App\Modules\Sparepart\Contracts\SparepartRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SparepartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SparepartRepositoryInterface::class, SparepartRepository::class);
        $this->app->bind(SparepartService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:sparepart'])
            ->group(function () {
                Route::get('sparepart/import/template', [SparepartController::class, 'downloadTemplate']);
                Route::post('sparepart/import', [SparepartController::class, 'import']);
                Route::post('sparepart/{id}/stok', [SparepartController::class, 'mutasiStok']);
                Route::get('sparepart/{id}/mutasi', [SparepartController::class, 'listMutasi']);
                Route::post('sparepart', [SparepartController::class, 'store']);
                Route::put('sparepart/{id}', [SparepartController::class, 'update']);
                Route::patch('sparepart/{id}', [SparepartController::class, 'update']);
                Route::delete('sparepart/{id}', [SparepartController::class, 'destroy']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:sparepart|perawatan-armada|pembelian-sparepart'])
            ->group(function () {
                Route::get('sparepart', [SparepartController::class, 'index']);
                Route::get('sparepart/{id}/riwayat-harga', [SparepartController::class, 'listRiwayatHarga']);
                Route::get('sparepart/{id}', [SparepartController::class, 'show']);
            });
    }
}
