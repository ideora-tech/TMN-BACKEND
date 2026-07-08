<?php

declare(strict_types=1);

namespace App\Modules\Notifikasi;

use App\Modules\Notifikasi\Contracts\NotifikasiRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class NotifikasiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotifikasiRepositoryInterface::class, NotifikasiRepository::class);
        $this->app->bind(NotifikasiService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::get('notifikasi', [NotifikasiController::class, 'index']);
                Route::get('notifikasi/unread-count', [NotifikasiController::class, 'unreadCount']);
                Route::put('notifikasi/baca-semua', [NotifikasiController::class, 'bacaSemua']);
                Route::put('notifikasi/{id}/baca', [NotifikasiController::class, 'baca']);
            });
    }
}