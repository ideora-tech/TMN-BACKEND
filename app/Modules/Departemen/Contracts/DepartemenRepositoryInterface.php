<?php

declare(strict_types=1);

namespace App\Modules\Departemen\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface DepartemenRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function tree(string $idPerusahaan): array;
    public function findById(string $id): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
