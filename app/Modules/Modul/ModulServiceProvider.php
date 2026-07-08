<?php

declare(strict_types=1);

namespace App\Modules\Modul;

use App\Modules\Modul\Contracts\ModulRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModulServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ModulRepositoryInterface::class, ModulRepository::class);
        $this->app->bind(ModulService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'role:SUPERADMIN,ADMIN,MANAGER'])
            ->group(function () {
                Route::apiResource('modul', ModulController::class)
                    ->parameters(['modul' => 'id']);
            });
    }
}
