<?php

declare(strict_types=1);

namespace App\Modules\Departemen;

use App\Modules\Departemen\Contracts\DepartemenRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DepartemenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DepartemenRepositoryInterface::class, DepartemenRepository::class);
        $this->app->bind(DepartemenService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::get('departemen/tree', [DepartemenController::class, 'tree']);
                Route::apiResource('departemen', DepartemenController::class)
                    ->parameters(['departemen' => 'id']);
            });
    }
}
