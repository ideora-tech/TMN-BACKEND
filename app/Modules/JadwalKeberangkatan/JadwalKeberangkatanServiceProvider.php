<?php

declare(strict_types=1);

namespace App\Modules\JadwalKeberangkatan;

use App\Modules\JadwalKeberangkatan\Contracts\JadwalKeberangkatanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class JadwalKeberangkatanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JadwalKeberangkatanRepositoryInterface::class, JadwalKeberangkatanRepository::class);
        $this->app->bind(JadwalKeberangkatanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:jadwal'])
            ->group(function () {
                // static routes MUST be registered before apiResource to avoid conflict
                Route::get('jadwal/saya', [JadwalKeberangkatanController::class, 'saya']);
                Route::get('jadwal/supir/{idSupir}', [JadwalKeberangkatanController::class, 'bySupir']);

                Route::apiResource('jadwal', JadwalKeberangkatanController::class)
                    ->parameters(['jadwal' => 'id']);
            });
    }
}
