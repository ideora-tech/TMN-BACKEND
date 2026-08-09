<?php

declare(strict_types=1);

namespace App\Modules\TokenPerangkat;

use App\Modules\TokenPerangkat\Contracts\TokenPerangkatRepositoryInterface;

class TokenPerangkatService
{
    public function __construct(private readonly TokenPerangkatRepositoryInterface $repo) {}

    public function daftar(string $idPengguna, string $token, string $platform): void
    {
        $this->repo->upsert($idPengguna, $token, $platform);
    }

    public function hapus(string $idPengguna, string $token): void
    {
        $this->repo->hapusByToken($idPengguna, $token);
    }
}
