<?php

declare(strict_types=1);

namespace App\Modules\BriefingSupir;

use App\Modules\BriefingSupir\Contracts\BriefingSupirRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BriefingSupirServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BriefingSupirRepositoryInterface::class, BriefingSupirRepository::class);
        $this->app->bind(BriefingSupirService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::get('penugasan/{idPenugasan}/briefing', [BriefingSupirController::class, 'index']);
                Route::post('penugasan/{idPenugasan}/briefing', [BriefingSupirController::class, 'store']);
                Route::get('briefing/{id}', [BriefingSupirController::class, 'show']);
                Route::put('briefing/{id}', [BriefingSupirController::class, 'update']);
                Route::delete('briefing/{id}', [BriefingSupirController::class, 'destroy']);
            });
    }
}
