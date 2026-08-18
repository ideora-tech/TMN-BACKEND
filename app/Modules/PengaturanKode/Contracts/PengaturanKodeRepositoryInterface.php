<?php

declare(strict_types=1);

namespace App\Modules\PengaturanKode\Contracts;

interface PengaturanKodeRepositoryInterface
{
    public function allByPerusahaan(string $idPerusahaan): array;

    public function findByEntitas(string $idPerusahaan, string $entitas): ?object;

    public function upsert(string $idPerusahaan, string $entitas, array $data): object;
}
