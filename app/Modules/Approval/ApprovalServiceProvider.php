<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Modules\Approval\Contracts\ApprovalRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ApprovalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ApprovalRepositoryInterface::class, ApprovalRepository::class);
        $this->app->bind(ApprovalService::class);
        $this->app->bind(ApprovalResolverService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::middleware('role:SUPERADMIN,ADMIN')->group(function () {
                    Route::get('approval-event-type', [ApprovalController::class, 'indexEventType']);
                    Route::post('approval-event-type', [ApprovalController::class, 'storeEventType']);
                    Route::put('approval-event-type/{id}', [ApprovalController::class, 'updateEventType']);
                    Route::post('approval-event-type/{id}/approver', [ApprovalController::class, 'storeConfigApprover']);
                    Route::delete('approval-event-type/{id}/approver/{idConfig}', [ApprovalController::class, 'destroyConfigApprover']);
                });

                Route::get('approval-pengajuan/menunggu-saya', [ApprovalController::class, 'menungguSaya']);
                Route::patch('approval-pengajuan/{id}/keputusan', [ApprovalController::class, 'putuskan']);
            });
    }
}
