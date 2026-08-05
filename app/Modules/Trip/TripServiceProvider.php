<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TripServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TripRepositoryInterface::class, TripRepository::class);
        $this->app->bind(TripService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:trip'])
            ->group(function () {
                Route::get('trip', [TripController::class, 'index']);
                Route::post('trip', [TripController::class, 'store']);
                Route::post('trip/mulai', [TripController::class, 'mulai']);
                Route::get('trip/penugasan-saya/{idPenugasan}', [TripController::class, 'penugasanSaya']);
                Route::get('trip/riwayat-saya', [TripController::class, 'riwayatSaya']);
                Route::get('trip/jadwal-saya', [TripController::class, 'jadwalSaya']);
                Route::post('trip/mulai-saya', [TripController::class, 'mulaiSaya']);
                Route::get('trip/ringkasan-proyek', [TripController::class, 'ringkasanProyek']);
                Route::get('trip/settlement', [TripController::class, 'settlementIndex']);
                Route::get('trip/rekap-supir/export/excel', [TripController::class, 'exportRekapSupirExcel']);
                Route::get('trip/rekap-supir/export/pdf', [TripController::class, 'exportRekapSupirPdf']);
                Route::get('trip/{id}', [TripController::class, 'show']);
                Route::delete('trip/{id}', [TripController::class, 'destroy']);

                Route::post('trip/{id}/checkin', [TripController::class, 'checkin']);
                Route::post('trip/{id}/checkout', [TripController::class, 'checkout']);
                Route::post('trip/{idTrip}/checkout-saya', [TripController::class, 'checkoutSaya']);
                Route::post('trip/{id}/batalkan', [TripController::class, 'batalkan']);
                Route::get('trip/{id}/rekap-biaya', [TripController::class, 'rekapBiaya']);
                Route::post('trip/{id}/settlement/lunas', [TripController::class, 'tandaiLunas']);
                Route::post('trip/{id}/settlement/batal', [TripController::class, 'batalkanLunas']);
                Route::put('trip/{id}/uang-jalan', [TripController::class, 'updateUangJalan']);
            });
    }
}
