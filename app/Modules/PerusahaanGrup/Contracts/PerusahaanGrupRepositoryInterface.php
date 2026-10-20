<?php

declare(strict_types=1);

namespace App\Modules\PerusahaanGrup\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface PerusahaanGrupRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?bool $aktif = null): LengthAwarePaginator;
    public function listAktifByPerusahaan(string $idPerusahaan): array;
    public function findById(string $id): ?object;
    public function findBySingkatan(string $idPerusahaan, string $singkatan, ?string $kecualiId = null): ?object;
    public function findBySingkatanAtauNama(string $idPerusahaan, string $teks): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function dipakaiArmada(string $id): bool;
}
