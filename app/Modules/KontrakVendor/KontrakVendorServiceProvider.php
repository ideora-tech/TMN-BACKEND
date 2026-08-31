<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor;

use App\Modules\KontrakVendor\Contracts\KontrakVendorRepositoryInterface;
use App\Events\ApprovalDiputuskan;
use App\Modules\KontrakVendor\Listeners\KontrakVendorApprovalListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KontrakVendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KontrakVendorRepositoryInterface::class, KontrakVendorRepository::class);
        $this->app->bind(KontrakVendorService::class);
    }

    public function boot(): void
    {
        Event::listen(ApprovalDiputuskan::class, [KontrakVendorApprovalListener::class, 'handle']);

        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:vendor'])
            ->group(function () {
                // Nested under proyek: list + create
                Route::get('proyek/{idProyek}/kontrak', [KontrakVendorController::class, 'indexByProyek']);
                Route::post('proyek/{idProyek}/kontrak', [KontrakVendorController::class, 'storeForProyek']);

                // Standalone CRUD for kontrak-vendor
                Route::get('kontrak-vendor', [KontrakVendorController::class, 'index']);
                Route::post('kontrak-vendor', [KontrakVendorController::class, 'store']);
                Route::get('kontrak-vendor/template-pasangan', [KontrakVendorController::class, 'templatePasangan']);
                Route::post('kontrak-vendor/parse-pasangan', [KontrakVendorController::class, 'parsePasangan']);
                Route::post('kontrak-vendor/parse-unit', [KontrakVendorController::class, 'parseUnit']);
                Route::post('kontrak-vendor/{id}/timpa-unit', [KontrakVendorController::class, 'timpaUnit']);
                Route::post('kontrak-vendor/{id}/timpa-pasangan', [KontrakVendorController::class, 'timpaPasangan']);
                Route::post('kontrak-vendor/{id}/ajukan-approval', [KontrakVendorController::class, 'ajukanApproval']);
                Route::post('kontrak-vendor/{id}/timpa-supir', [KontrakVendorController::class, 'timpaSupir']);
                Route::post('kontrak-vendor/parse-supir', [KontrakVendorController::class, 'parseSupir']);
                Route::get('kontrak-vendor/{id}/export/pdf', [KontrakVendorController::class, 'exportPdf']);
                Route::get('kontrak-vendor/{id}/export/excel', [KontrakVendorController::class, 'exportExcel']);
                Route::get('kontrak-vendor/{id}', [KontrakVendorController::class, 'show']);
                Route::put('kontrak-vendor/{id}', [KontrakVendorController::class, 'update']);
                Route::delete('kontrak-vendor/{id}', [KontrakVendorController::class, 'destroy']);
            });
    }
}
