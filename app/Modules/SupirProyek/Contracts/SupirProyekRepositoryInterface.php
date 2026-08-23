<?php

declare(strict_types=1);

namespace App\Modules\SupirProyek\Contracts;

use App\Modules\SupirProyek\SupirProyekModel;

interface SupirProyekRepositoryInterface
{
    public function listByProyek(string $idProyek, string $idPerusahaan): array;
    public function terdaftar(string $idProyek, string $idSupir): bool;
    public function create(array $data): SupirProyekModel;
    public function findByIdMilikPerusahaan(string $id, string $idPerusahaan): ?SupirProyekModel;
    public function delete(SupirProyekModel $m): void;
    public function supirMilikPerusahaan(string $idSupir, string $idPerusahaan): bool;
    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool;
}
