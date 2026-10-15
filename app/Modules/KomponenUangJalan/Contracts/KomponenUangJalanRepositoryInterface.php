<?php

declare(strict_types=1);

namespace App\Modules\KomponenUangJalan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface KomponenUangJalanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search, ?bool $aktif): LengthAwarePaginator;
    public function listAktifByPerusahaan(string $idPerusahaan): array;
    public function findById(string $id): ?object;
    public function findByIds(array $ids, string $idPerusahaan): array;
    public function namaTerpakai(string $idPerusahaan, string $nama, ?string $kecualiId): bool;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function pernahAda(string $idPerusahaan): bool;
    public function sudahDipakai(string $id): bool;
}
