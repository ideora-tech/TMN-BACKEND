<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface SparepartRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByIdForUpdate(string $id): ?object;
    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function countActiveUsage(string $idSparepart): int;
    public function setStok(string $id, int $stokBaru): void;
    public function insertMutasi(array $data): void;
    public function paginateMutasi(string $idSparepart, int $page, int $limit): LengthAwarePaginator;
}
