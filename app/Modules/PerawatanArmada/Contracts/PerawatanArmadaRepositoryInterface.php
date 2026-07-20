<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PerawatanArmadaRepositoryInterface
{
    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator;
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $status, bool $jatuhTempo = false): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;

    public function getActiveLines(string $idPerawatan): array;
    public function insertLine(array $data): void;
    public function softDeleteLines(string $idPerawatan): void;
    public function getSparepartForUpdate(string $idSparepart): ?object;
    public function setSparepartStok(string $idSparepart, int $stokBaru): void;
    public function insertSparepartMutasi(array $data): void;
    public function getJenisPerawatanNama(string $idJenisPerawatan): ?string;
}
