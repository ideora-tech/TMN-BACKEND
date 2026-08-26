<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip;

use App\Modules\EvaluasiTrip\Contracts\EvaluasiTripRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class EvaluasiTripServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EvaluasiTripRepositoryInterface::class, EvaluasiTripRepository::class);
        $this->app->bind(EvaluasiTripService::class);
    }

    public function boot(): void
    {
        /**
         * Semua endpoint evaluasi di bawah izin:vendor — input evaluasi
         * sekarang dari halaman Evaluasi Vendor (kartu evaluasi per-trip di
         * Detail Trip sudah dihapus), jadi izin:trip tidak lagi relevan.
         */
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:vendor'])
            ->group(function () {
                Route::get('penugasan/{idPenugasan}/evaluasi', [EvaluasiTripController::class, 'showByPenugasan']);
                Route::post('penugasan/{idPenugasan}/evaluasi', [EvaluasiTripController::class, 'storeByPenugasan']);
                Route::put('evaluasi/{id}', [EvaluasiTripController::class, 'update']);
                Route::get('evaluasi-vendor/rekap', [EvaluasiTripController::class, 'rekapVendor']);
                Route::get('evaluasi-vendor/penugasan', [EvaluasiTripController::class, 'penugasanUntukEvaluasi']);
                Route::get('vendor/{idVendor}/evaluasi', [EvaluasiTripController::class, 'listByVendor']);
            });
    }
}
