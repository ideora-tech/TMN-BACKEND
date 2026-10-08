<?php

declare(strict_types=1);

namespace App\Modules\Kasbon;

use App\Modules\Kasbon\Contracts\KasbonRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KasbonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KasbonRepositoryInterface::class, KasbonRepository::class);
        $this->app->bind(KasbonService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:kasbon'])
            ->group(function () {
                Route::get('kasbon/opsi/karyawan', [KasbonController::class, 'opsiKaryawan']);
                Route::get('kasbon/ringkasan', [KasbonController::class, 'ringkasan']);
                Route::get('kasbon/export/excel', [KasbonController::class, 'exportExcel']);
                Route::get('kasbon/{id}/riwayat', [KasbonController::class, 'riwayat']);
                Route::patch('kasbon/{id}/cicilan', [KasbonController::class, 'ubahCicilan']);
                Route::middleware('role:' . implode(',', KasbonService::PERAN_PELUNASAN))->group(function () {
                    Route::post('kasbon/{id}/pelunasan', [KasbonController::class, 'catatPelunasan']);
                    Route::delete('kasbon/{id}/pelunasan/{idPembayaran}', [KasbonController::class, 'hapusPelunasan']);
                });
                Route::apiResource('kasbon', KasbonController::class)
                    ->parameters(['kasbon' => 'id']);
            });
    }
}
