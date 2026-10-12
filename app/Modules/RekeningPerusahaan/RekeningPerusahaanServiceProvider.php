<?php

declare(strict_types=1);

namespace App\Modules\RekeningPerusahaan;

use App\Modules\RekeningPerusahaan\Contracts\RekeningPerusahaanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RekeningPerusahaanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RekeningPerusahaanRepositoryInterface::class, RekeningPerusahaanRepository::class);
        $this->app->bind(RekeningPerusahaanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:rekening-perusahaan'])
            ->group(function () {
                Route::apiResource('rekening-perusahaan', RekeningPerusahaanController::class)
                    ->parameters(['rekening-perusahaan' => 'id']);
            });
    }
}
