<?php

declare(strict_types=1);

namespace App\Modules\TokenPerangkat\Contracts;

interface TokenPerangkatRepositoryInterface
{
    public function upsert(string $idPengguna, string $token, string $platform): void;
    public function hapusByToken(string $idPengguna, string $token): void;
    public function tokensUntukPengguna(string $idPengguna): array;
    public function hapusTokenMati(string $token): void;
}
