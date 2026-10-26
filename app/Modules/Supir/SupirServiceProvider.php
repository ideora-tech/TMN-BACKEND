<?php
declare(strict_types=1);
namespace App\Modules\Supir;

use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SupirServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupirRepositoryInterface::class, SupirRepository::class);
        $this->app->bind(SupirService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:supir'])
            ->group(function () {
                Route::get('supir/me', [SupirController::class, 'me']);
                Route::get('supir/me/dokumen', [SupirController::class, 'dokumenSaya']);
                Route::get('supir/me/unit', [SupirController::class, 'unitSaya']);
                Route::get('supir/{id}/dokumen', [SupirController::class, 'dokumen']);
                Route::get('supir/{id}/dokumen-karyawan', [SupirController::class, 'dokumenKaryawan']);
                Route::post('supir/{id}/dokumen-karyawan', [SupirController::class, 'tambahDokumenKaryawan']);
                Route::put('supir/{id}/dokumen-karyawan/{idDokumen}', [SupirController::class, 'ubahDokumenKaryawan']);
                Route::delete('supir/{id}/dokumen-karyawan/{idDokumen}', [SupirController::class, 'hapusDokumenKaryawan']);
                Route::get('supir/opsi-pengguna', [SupirController::class, 'opsiPengguna']);
                Route::get('supir/opsi-karyawan', [SupirController::class, 'opsiKaryawan']);
                Route::get('supir/import/template', [SupirController::class, 'downloadTemplate']);
                Route::post('supir/import', [SupirController::class, 'import']);
                Route::get('supir/{id}/riwayat-armada/export/excel', [SupirController::class, 'exportRiwayatArmada']);
                Route::get('supir/{id}/riwayat-trip/export/excel', [SupirController::class, 'exportRiwayatTrip']);
                Route::apiResource('supir', SupirController::class)
                    ->parameters(['supir' => 'id']);
            });
    }
}
