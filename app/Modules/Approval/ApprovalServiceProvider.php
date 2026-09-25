<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Modules\Approval\Contracts\ApprovalRepositoryInterface;
use App\Modules\Approval\Contracts\RincianReferensiRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ApprovalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ApprovalRepositoryInterface::class, ApprovalRepository::class);
        $this->app->bind(RincianReferensiRepositoryInterface::class, RincianReferensiRepository::class);
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
                    Route::delete('approval-event-type/{id}', [ApprovalController::class, 'destroyEventType']);
                    Route::get('approval-event-type/{id}/approver', [ApprovalController::class, 'indexConfigApprover']);
                    Route::post('approval-event-type/{id}/approver', [ApprovalController::class, 'storeConfigApprover']);
                    Route::delete('approval-event-type/{id}/approver/{idConfig}', [ApprovalController::class, 'destroyConfigApprover']);
                });

                Route::get('approval-pengajuan/menunggu-saya', [ApprovalController::class, 'menungguSaya']);
                Route::get('approval-pengajuan/{id}/rincian', [ApprovalController::class, 'rincianPengajuan']);
                Route::get('approval-pengajuan/riwayat-saya', [ApprovalController::class, 'riwayatSaya']);
                Route::get('approval-pengajuan/status-referensi', [ApprovalController::class, 'statusReferensi']);
                Route::post('approval-pengajuan/lampiran', [ApprovalController::class, 'uploadLampiran']);
                Route::get('approval-pengajuan/export-saya', [ApprovalController::class, 'exportSaya']);
                Route::patch('approval-pengajuan/{id}/keputusan', [ApprovalController::class, 'putuskan']);
            });
    }
}
