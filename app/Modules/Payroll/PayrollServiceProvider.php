<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

use App\Modules\Payroll\Contracts\PayrollRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PayrollRepositoryInterface::class, PayrollRepository::class);
        $this->app->bind(PayrollService::class);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'auth:sanctum', 'izin:karyawan'])
            ->group(function () {
                Route::get('payroll/pengaturan', [PayrollController::class, 'pengaturan']);
                Route::put('payroll/pengaturan', [PayrollController::class, 'simpanPengaturan']);
                Route::get('payroll/preview-rentang', [PayrollController::class, 'previewRentang']);

                Route::get('payroll/periode', [PayrollController::class, 'indexPeriode']);
                Route::post('payroll/periode', [PayrollController::class, 'storePeriode']);
                Route::get('payroll/periode/{id}', [PayrollController::class, 'showPeriode']);
                Route::delete('payroll/periode/{id}', [PayrollController::class, 'destroyPeriode']);
                Route::post('payroll/periode/{id}/generate', [PayrollController::class, 'generate']);
                Route::get('payroll/periode/{id}/pengajuan', [PayrollController::class, 'infoPengajuan']);
                Route::get('payroll/periode/{id}/import/template', [PayrollController::class, 'downloadTemplate']);
                Route::post('payroll/periode/{id}/import', [PayrollController::class, 'importExcel']);
                Route::post('payroll/periode/{id}/finalisasi', [PayrollController::class, 'finalisasi']);
                Route::post('payroll/periode/{id}/batal-finalisasi', [PayrollController::class, 'batalFinalisasi']);

                Route::put('payroll/slip/{id}', [PayrollController::class, 'updateSlip']);
                Route::get('payroll/slip/{id}/pdf', [PayrollController::class, 'slipPdf']);
            });
    }
}
