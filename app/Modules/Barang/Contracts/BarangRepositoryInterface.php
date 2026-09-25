<?php

declare(strict_types=1);

namespace App\Modules\Barang\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface BarangRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search, ?string $idKategori, bool $stokMenipis): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByIdForUpdate(string $id): ?object;
    public function findByKode(string $idPerusahaan, string $kode): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function softDelete(object $record): void;
    public function dipakaiRelasiLain(string $idBarang): bool;
    public function hitungMenipis(string $idPerusahaan): int;
    public function setStok(string $id, int $stokBaru): void;
    public function setHargaStandar(string $id, float $harga): void;
    public function insertMutasi(array $data): void;
    public function paginateMutasi(string $idBarang, int $page, int $limit): LengthAwarePaginator;
    /** @return object[] */
    public function listKategori(string $idPerusahaan, bool $hanyaAktif): array;
    public function findKategori(string $id): ?object;
    public function findKategoriByNama(string $idPerusahaan, string $nama, ?string $excludeId = null): ?object;
    public function createKategori(array $data): object;
    public function updateKategori(object $record, array $data): object;
    public function softDeleteKategori(object $record): void;
    public function kategoriDipakai(string $idKategori): bool;
}
