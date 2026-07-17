<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan;

use App\Modules\JenisPerawatan\Contracts\JenisPerawatanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class JenisPerawatanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JenisPerawatanRepositoryInterface::class, JenisPerawatanRepository::class);
        $this->app->bind(JenisPerawatanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('jenis-perawatan', JenisPerawatanController::class)
                    ->parameters(['jenis-perawatan' => 'id']);
            });
    }
}
