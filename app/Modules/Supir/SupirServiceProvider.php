<?php
declare(strict_types=1);
namespace App\Modules\Supir;

use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SupirServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupirRepositoryInterface::class, SupirRepository::class);
        $this->app->bind(SupirService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:supir'])
            ->group(function () {
                Route::get('supir/me', [SupirController::class, 'me']);
                Route::get('supir/import/template', [SupirController::class, 'downloadTemplate']);
                Route::post('supir/import', [SupirController::class, 'import']);
                Route::apiResource('supir', SupirController::class)
                    ->parameters(['supir' => 'id']);
            });
    }
}
