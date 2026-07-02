<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Models\Pengguna;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(private readonly AuthRepository $repository) {}

    public function login(string $username, string $password): array
    {
        $pengguna = $this->repository->findActiveByUsernameOrEmail($username);

        if (!$pengguna || !Hash::check($password, $pengguna->kata_sandi)) {
            abort(401, 'Username atau password salah');
        }

        $this->repository->updateLoginTimestamp($pengguna);

        $token = $pengguna->createToken('api-token')->plainTextToken;

        return ['token' => $token, 'pengguna' => $pengguna];
    }

    public function logout(Pengguna $pengguna): void
    {
        $pengguna->currentAccessToken()->delete();
    }
}
