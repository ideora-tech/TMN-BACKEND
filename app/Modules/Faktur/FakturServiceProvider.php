<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Events\ApprovalDiputuskan;
use App\Modules\Faktur\Contracts\FakturItemRepositoryInterface;
use App\Modules\Faktur\Contracts\FakturRepositoryInterface;
use App\Modules\Faktur\Listeners\FakturApprovalListener;
use Illuminate\Support\Facades\Event;
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
        Event::listen(ApprovalDiputuskan::class, [FakturApprovalListener::class, 'handle']);

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:faktur'])
            ->group(function () {
                Route::get('faktur/{id}/export/excel', [FakturController::class, 'exportExcel']);
                Route::get('faktur/{id}/export/pdf', [FakturController::class, 'exportPdf']);

                Route::apiResource('faktur', FakturController::class)
                    ->parameters(['faktur' => 'id']);

                Route::patch('faktur/{id}/status', [FakturController::class, 'updateStatus']);
                Route::post('faktur/{id}/ajukan-approval', [FakturController::class, 'ajukanApproval']);
            });
    }
}
