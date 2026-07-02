<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PenugasanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PenugasanRepositoryInterface::class, PenugasanRepository::class);
        $this->app->bind(PenugasanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('penugasan', PenugasanController::class)
                    ->parameters(['penugasan' => 'id']);
            });
    }
}
