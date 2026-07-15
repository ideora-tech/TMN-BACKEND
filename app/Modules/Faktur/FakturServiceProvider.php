<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Modules\Faktur\Contracts\FakturItemRepositoryInterface;
use App\Modules\Faktur\Contracts\FakturRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FakturServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FakturRepositoryInterface::class, FakturRepository::class);
        $this->app->bind(FakturItemRepositoryInterface::class, FakturItemRepository::class);
        $this->app->bind(FakturService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:faktur'])
            ->group(function () {
                Route::get('faktur/export/excel', [FakturController::class, 'exportExcel']);
                Route::get('faktur/export/pdf', [FakturController::class, 'exportPdf']);

                Route::apiResource('faktur', FakturController::class)
                    ->parameters(['faktur' => 'id']);

                Route::patch('faktur/{id}/status', [FakturController::class, 'updateStatus']);
            });
    }
}
