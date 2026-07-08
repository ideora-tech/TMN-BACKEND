<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Models\Pengguna;

class AuthRepository
{
    public function findActiveByEmail(string $email): ?Pengguna
    {
        return Pengguna::where('email', $email)
            ->whereNull('dihapus_pada')
            ->where('aktif', 1)
            ->first();
    }

    public function findActiveByUsername(string $username): ?Pengguna
    {
        return Pengguna::where('username', $username)
            ->whereNull('dihapus_pada')
            ->where('aktif', 1)
            ->first();
    }

    public function findActiveByUsernameOrEmail(string $identifier): ?Pengguna
    {
        return Pengguna::with('karyawan')
            ->where(function ($q) use ($identifier) {
                $q->where('username', $identifier)->orWhere('email', $identifier);
            })
            ->whereNull('dihapus_pada')
            ->where('aktif', 1)
            ->first();
    }

    public function updateLoginTimestamp(Pengguna $pengguna): void
    {
        $pengguna->login_terakhir = now();
        $pengguna->saveQuietly();
    }
}
