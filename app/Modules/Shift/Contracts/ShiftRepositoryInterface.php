<?php

declare(strict_types=1);

namespace App\Modules\Shift\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface ShiftRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function countActiveUsage(string $idShift): int;
}
