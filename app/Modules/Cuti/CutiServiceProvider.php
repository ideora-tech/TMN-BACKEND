<?php

declare(strict_types=1);

namespace App\Modules\Cuti;

use App\Modules\Cuti\Contracts\CutiRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CutiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CutiRepositoryInterface::class, CutiRepository::class);
        $this->app->bind(CutiService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:karyawan'])
            ->group(function () {
                Route::get('jenis-cuti', [CutiController::class, 'indexJenis']);
                Route::post('jenis-cuti', [CutiController::class, 'storeJenis']);
                Route::put('jenis-cuti/{id}', [CutiController::class, 'updateJenis']);
                Route::delete('jenis-cuti/{id}', [CutiController::class, 'destroyJenis']);

                Route::get('pengajuan-cuti/saya', [CutiController::class, 'indexPengajuanSaya']);
                Route::post('pengajuan-cuti/saya', [CutiController::class, 'storePengajuanSaya']);
                Route::post('pengajuan-cuti/saya/{id}/batalkan', [CutiController::class, 'batalkanSaya']);
                Route::get('saldo-cuti/saya', [CutiController::class, 'saldoSaya']);

                Route::get('pengajuan-cuti', [CutiController::class, 'indexPengajuan']);
                Route::get('pengajuan-cuti/aktif', [CutiController::class, 'cutiAktif']);
                Route::post('pengajuan-cuti', [CutiController::class, 'storePengajuan']);
                Route::post('pengajuan-cuti/{id}/setujui', [CutiController::class, 'setujui']);
                Route::post('pengajuan-cuti/{id}/tolak', [CutiController::class, 'tolak']);
                Route::post('pengajuan-cuti/{id}/batalkan', [CutiController::class, 'batalkan']);

                Route::get('saldo-cuti', [CutiController::class, 'saldo']);
                Route::get('saldo-cuti/rekap', [CutiController::class, 'rekapSaldo']);
                Route::post('saldo-cuti/penyesuaian', [CutiController::class, 'penyesuaianSaldo']);
            });
    }
}
