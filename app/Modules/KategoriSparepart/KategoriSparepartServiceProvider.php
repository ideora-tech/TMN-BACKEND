<?php
// app/Modules/KategoriSparepart/KategoriSparepartServiceProvider.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart;

use App\Modules\KategoriSparepart\Contracts\KategoriSparepartRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KategoriSparepartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KategoriSparepartRepositoryInterface::class, KategoriSparepartRepository::class);
        $this->app->bind(KategoriSparepartService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('kategori-sparepart', KategoriSparepartController::class)
                    ->parameters(['kategori-sparepart' => 'id']);
            });
    }
}
