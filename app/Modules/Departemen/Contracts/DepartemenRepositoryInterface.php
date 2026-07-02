<?php

declare(strict_types=1);

namespace App\Modules\Departemen\Contracts;

use App\Modules\Departemen\DepartemenModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface DepartemenRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function tree(string $idPerusahaan): array;
    public function findById(string $id): ?DepartemenModel;
    public function create(array $data): DepartemenModel;
    public function update(DepartemenModel $model, array $data): DepartemenModel;
    public function delete(DepartemenModel $model): void;
}
