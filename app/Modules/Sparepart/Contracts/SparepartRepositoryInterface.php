<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface SparepartRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search, ?string $idKategoriSparepart = null): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByIdForUpdate(string $id): ?object;
    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function countActiveUsage(string $idSparepart): int;
    public function dipakaiRelasiLain(string $idSparepart): bool;
    public function setStok(string $id, int $stokBaru): void;
    public function insertMutasi(array $data): void;
    public function paginateMutasi(string $idSparepart, int $page, int $limit): LengthAwarePaginator;
    public function insertRiwayatHarga(array $data): void;
    public function paginateRiwayatHarga(string $idSparepart, int $page, int $limit): LengthAwarePaginator;

    /** @return array<string, object> */
    public function hargaBeliTerakhirByIds(array $ids): array;

    /** @return array<string, object[]> */
    public function fotoByIds(array $ids): array;
    public function hitungFoto(string $idSparepart): int;
    public function urutanFotoTerakhir(string $idSparepart): int;
    public function insertFoto(array $data): void;
    public function findFoto(string $idSparepart, string $idFoto): ?object;
    public function softDeleteFoto(string $idFoto): void;
}
