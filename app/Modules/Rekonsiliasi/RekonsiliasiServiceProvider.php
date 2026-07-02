<?php

declare(strict_types=1);

namespace App\Modules\Rekonsiliasi;

use App\Modules\Rekonsiliasi\Contracts\RekonsiliasiRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RekonsiliasiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RekonsiliasiRepositoryInterface::class, RekonsiliasiRepository::class);
        $this->app->bind(RekonsiliasiService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('rekonsiliasi', RekonsiliasiController::class)
                    ->parameters(['rekonsiliasi' => 'id']);

                Route::get('faktur/{idFaktur}/rekonsiliasi', [RekonsiliasiController::class, 'indexByFaktur']);
            });
    }
}
