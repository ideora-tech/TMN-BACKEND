<?php
// app/Modules/KategoriSparepart/Contracts/KategoriSparepartRepositoryInterface.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface KategoriSparepartRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function countActiveUsage(string $idKategoriSparepart): int;
}
