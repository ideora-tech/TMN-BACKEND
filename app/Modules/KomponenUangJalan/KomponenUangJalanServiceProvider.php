<?php

declare(strict_types=1);

namespace App\Modules\KomponenUangJalan;

use App\Modules\KomponenUangJalan\Contracts\KomponenUangJalanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KomponenUangJalanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KomponenUangJalanRepositoryInterface::class, KomponenUangJalanRepository::class);
        $this->app->bind(KomponenUangJalanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:komponen-uang-jalan|project|uang-jalan'])
            ->group(function () {
                Route::get('komponen-uang-jalan/opsi', [KomponenUangJalanController::class, 'opsi']);
            });

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:komponen-uang-jalan'])
            ->group(function () {
                Route::get('komponen-uang-jalan', [KomponenUangJalanController::class, 'index']);
                Route::get('komponen-uang-jalan/{id}', [KomponenUangJalanController::class, 'show']);
                Route::post('komponen-uang-jalan', [KomponenUangJalanController::class, 'store']);
                Route::put('komponen-uang-jalan/{id}', [KomponenUangJalanController::class, 'update']);
                Route::delete('komponen-uang-jalan/{id}', [KomponenUangJalanController::class, 'destroy']);
            });
    }
}
