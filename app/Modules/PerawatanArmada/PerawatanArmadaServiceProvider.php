<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Modules\PerawatanArmada\Contracts\PerawatanArmadaRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PerawatanArmadaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PerawatanArmadaRepositoryInterface::class, PerawatanArmadaRepository::class);
        $this->app->bind(PerawatanArmadaService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:armada'])
            ->group(function () {
                Route::get('perawatan-armada', [PerawatanArmadaController::class, 'index']);
                Route::get('perawatan-armada/rekap-per-unit', [PerawatanArmadaController::class, 'rekapPerUnit']);
                Route::get('perawatan-armada/rekap-per-unit/export/excel', [PerawatanArmadaController::class, 'exportRekapExcel']);
                Route::get('perawatan-armada/rekap-per-unit/export/pdf', [PerawatanArmadaController::class, 'exportRekapPdf']);
                Route::get('armada/{idArmada}/perawatan/export/excel', [PerawatanArmadaController::class, 'exportUnitExcel']);
                Route::get('armada/{idArmada}/perawatan/export/pdf', [PerawatanArmadaController::class, 'exportUnitPdf']);
                Route::get('armada/{idArmada}/prediksi-perawatan', [PerawatanArmadaController::class, 'prediksiPerawatan']);
                Route::get('armada/{idArmada}/perawatan', [PerawatanArmadaController::class, 'indexByArmada']);
                Route::get('armada/{idArmada}/perawatan/{id}/pengajuan', [PerawatanArmadaController::class, 'infoPengajuan']);
                Route::get('armada/{idArmada}/perawatan/{id}', [PerawatanArmadaController::class, 'show']);
                Route::post('armada/{idArmada}/perawatan', [PerawatanArmadaController::class, 'store']);
                Route::post('armada/{idArmada}/perawatan/{id}/bukti', [PerawatanArmadaController::class, 'storeBukti']);
                Route::delete('armada/{idArmada}/perawatan/{id}/bukti/{idBukti}', [PerawatanArmadaController::class, 'destroyBukti']);
                Route::post('armada/{idArmada}/perawatan/{id}/batal', [PerawatanArmadaController::class, 'batal']);
                Route::put('armada/{idArmada}/perawatan/{id}', [PerawatanArmadaController::class, 'update']);
                Route::patch('armada/{idArmada}/perawatan/{id}', [PerawatanArmadaController::class, 'update']);
                Route::delete('armada/{idArmada}/perawatan/{id}', [PerawatanArmadaController::class, 'destroy']);
            });
    }
}
