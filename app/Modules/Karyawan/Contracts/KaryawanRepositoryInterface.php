<?php

declare(strict_types=1);

namespace App\Modules\Karyawan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface KaryawanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByNik(string $nik): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function exitHistory(string $idKaryawan): array;
}
