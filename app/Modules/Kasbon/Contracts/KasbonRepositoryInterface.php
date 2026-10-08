<?php

declare(strict_types=1);

namespace App\Modules\Kasbon\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface KasbonRepositoryInterface
{
    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $search = null,
        ?string $status = null,
        ?string $idKaryawan = null,
    ): LengthAwarePaginator;

    public function semuaByPerusahaan(string $idPerusahaan, ?string $search = null, ?string $status = null, ?string $idKaryawan = null): array;

    public function findById(string $id): ?object;

    public function kunci(array $idKasbon): void;

    public function hitungMenunggu(string $idPerusahaan): int;

    public function nomorBerikutnya(string $idPerusahaan): string;

    public function create(array $data): object;

    public function update(object $record, array $data): object;

    public function delete(object $record): void;

    public function findKaryawan(string $id, string $idPerusahaan): ?object;

    public function opsiKaryawan(string $idPerusahaan): array;

    public function pembayaranByKasbon(string $idKasbon): array;

    public function findPembayaran(string $idPembayaran, string $idKasbon): ?object;

    public function createPembayaran(array $data): void;

    public function deletePembayaran(object $record): void;

    public function hapusPembayaranPayroll(string $idPeriode): void;

    public function createRiwayatCicilan(array $data): void;

    public function riwayatCicilanByKasbon(string $idKasbon): array;

    /** @return list<object> */
    public function berjalanByPerusahaan(string $idPerusahaan, ?array $idKaryawan = null, bool $kunci = false): array;
}
