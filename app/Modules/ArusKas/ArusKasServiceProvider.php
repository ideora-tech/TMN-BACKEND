<?php

declare(strict_types=1);

namespace App\Modules\ArusKas;

use App\Modules\ArusKas\Contracts\ArusKasRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ArusKasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ArusKasRepositoryInterface::class, ArusKasRepository::class);
        $this->app->bind(ArusKasService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:arus-kas'])
            ->group(function () {
                Route::get('arus-kas', [ArusKasController::class, 'rekap']);
                Route::get('arus-kas/export/excel', [ArusKasController::class, 'exportExcel']);

                Route::get('arus-kas/pengajuan', [ArusKasController::class, 'indexPengajuan']);
                Route::get('arus-kas/pengajuan/{id}', [ArusKasController::class, 'showPengajuan']);
                Route::post('arus-kas/pengajuan', [ArusKasController::class, 'storePengajuan']);
                Route::put('arus-kas/pengajuan/{id}', [ArusKasController::class, 'updatePengajuan']);
                Route::delete('arus-kas/pengajuan/{id}', [ArusKasController::class, 'destroyPengajuan']);

                Route::get('arus-kas/pemasukan', [ArusKasController::class, 'indexPemasukan']);

                Route::middleware('role:SUPERADMIN,KEUANGAN')->group(function () {
                    Route::post('arus-kas/pemasukan', [ArusKasController::class, 'storePemasukan']);
                    Route::put('arus-kas/pemasukan/{id}', [ArusKasController::class, 'updatePemasukan']);
                    Route::delete('arus-kas/pemasukan/{id}', [ArusKasController::class, 'destroyPemasukan']);
                    Route::patch('arus-kas/pengajuan/{id}/cek', [ArusKasController::class, 'cekPengajuan']);
                    Route::patch('arus-kas/pengajuan/{id}/transfer', [ArusKasController::class, 'transferPengajuan']);
                });

                Route::middleware('role:SUPERADMIN,MANAGER')->group(function () {
                    Route::patch('arus-kas/pengajuan/{id}/setujui', [ArusKasController::class, 'setujuiPengajuan']);
                    Route::patch('arus-kas/pengajuan/{id}/tolak', [ArusKasController::class, 'tolakPengajuan']);
                });
            });
    }
}
