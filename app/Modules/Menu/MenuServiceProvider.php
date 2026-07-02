<?php

declare(strict_types=1);

namespace App\Modules\Menu;

use App\Modules\Menu\Contracts\MenuRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MenuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MenuRepositoryInterface::class, MenuRepository::class);
        $this->app->bind(MenuService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::get('menu/tree', [MenuController::class, 'tree']);
                Route::apiResource('menu', MenuController::class)
                    ->parameters(['menu' => 'id']);
            });
    }
}
