<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan;

use App\Modules\IntervalPerawatan\Contracts\IntervalPerawatanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class IntervalPerawatanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IntervalPerawatanRepositoryInterface::class, IntervalPerawatanRepository::class);
        $this->app->bind(IntervalPerawatanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:armada'])
            ->group(function () {
                // Route statis SEBELUM apiResource agar tidak tertangkap sebagai {id}.
                Route::get('interval-perawatan/resolusi', [IntervalPerawatanController::class, 'resolusi']);

                Route::apiResource('interval-perawatan', IntervalPerawatanController::class)
                    ->parameters(['interval-perawatan' => 'id']);
            });
    }
}
