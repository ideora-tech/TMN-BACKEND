<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface DokumenArmadaRepositoryInterface
{
    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator;
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $jenisDokumen): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findExpiring(string $idPerusahaan, int $days): array;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
