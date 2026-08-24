<?php

declare(strict_types=1);

namespace App\Modules\Jabatan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface JabatanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idDepartemen = null, ?string $search = null): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findAktifMilikPerusahaan(string $id, string $idPerusahaan): ?object;
    public function strukturOrganisasi(string $idPerusahaan): array;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
