<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface JenisKendaraanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByKode(string $idPerusahaan, string $kode): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
