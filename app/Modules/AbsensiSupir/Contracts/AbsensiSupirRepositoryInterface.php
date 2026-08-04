<?php

declare(strict_types=1);

namespace App\Modules\AbsensiSupir\Contracts;

interface AbsensiSupirRepositoryInterface
{
    public function findBySupirTanggal(string $idSupir, string $tanggal): ?object;

    public function create(array $data): object;

    public function update(string $idAbsensi, array $data): void;
}
