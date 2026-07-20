<?php
// app/Modules/PaketPerawatanSparepart/PaketPerawatanSparepartServiceProvider.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart;

use App\Modules\PaketPerawatanSparepart\Contracts\PaketPerawatanSparepartRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PaketPerawatanSparepartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaketPerawatanSparepartRepositoryInterface::class, PaketPerawatanSparepartRepository::class);
        $this->app->bind(PaketPerawatanSparepartService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:armada'])
            ->group(function () {
                Route::get('paket-perawatan-sparepart/resolusi', [PaketPerawatanSparepartController::class, 'resolusi']);

                Route::apiResource('paket-perawatan-sparepart', PaketPerawatanSparepartController::class)
                    ->parameters(['paket-perawatan-sparepart' => 'id']);
            });
    }
}
