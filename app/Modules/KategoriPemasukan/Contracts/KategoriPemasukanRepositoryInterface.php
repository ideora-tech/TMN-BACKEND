<?php

declare(strict_types=1);

namespace App\Modules\KategoriPemasukan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface KategoriPemasukanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?bool $aktif = null): LengthAwarePaginator;

    /** Semua kategori perusahaan, urut tampilan. */
    public function semuaByPerusahaan(string $idPerusahaan, bool $hanyaAktif = false): Collection;

    public function findById(string $id): ?object;

    public function findByKode(string $idPerusahaan, string $kode): ?object;

    public function findByNama(string $idPerusahaan, string $nama, ?string $kecualiId = null): ?object;

    /** @return list<string> */
    public function kodeTerpakai(string $idPerusahaan): array;

    public function create(array $data): object;

    public function update(object $record, array $data): object;

    public function delete(object $record): void;

    public function jumlahPemakaian(string $idPerusahaan, string $kode): int;
}
