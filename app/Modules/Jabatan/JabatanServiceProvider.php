<?php

declare(strict_types=1);

namespace App\Modules\Jabatan;

use App\Modules\Jabatan\Contracts\JabatanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class JabatanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JabatanRepositoryInterface::class, JabatanRepository::class);
        $this->app->bind(JabatanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('jabatan', JabatanController::class)
                    ->parameters(['jabatan' => 'id']);
            });
    }
}
