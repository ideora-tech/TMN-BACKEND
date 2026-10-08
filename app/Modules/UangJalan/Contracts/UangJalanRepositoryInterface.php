<?php

declare(strict_types=1);

namespace App\Modules\UangJalan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface UangJalanRepositoryInterface
{
    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $search = null,
        ?string $status = null,
        ?string $dari = null,
        ?string $sampai = null,
        ?string $idProyek = null,
    ): LengthAwarePaginator;

    public function findById(string $id): ?object;

    public function findSupir(string $id, string $idPerusahaan): ?object;

    public function findArmada(string $id, string $idPerusahaan): ?object;

    public function findVendor(string $id, string $idPerusahaan): ?object;

    public function findSupirVendor(string $id, string $idVendor): ?object;

    public function findArmadaVendor(string $id, string $idVendor): ?object;

    public function findRute(string $id, string $idPerusahaan): ?object;

    public function findProyek(string $id, string $idPerusahaan): ?object;

    public function findRuteProyek(string $id, string $idProyek): ?object;

    public function findPenugasan(string $id, string $idProyek): ?object;

    public function opsiInternal(string $idPerusahaan): array;

    public function opsiVendor(string $idVendor): array;

    public function opsiProyek(string $idPerusahaan): array;

    public function opsiRuteProyek(string $idProyek): array;

    public function jenisKendaraanArmada(string $id, string $idPerusahaan): ?string;

    public function jenisKendaraanArmadaVendor(string $id, string $idPerusahaan): ?string;

    public function rateCardRute(string $idProyek, string $idRute, ?string $idJenisKendaraan): ?object;

    public function opsiPenugasan(string $idProyek): array;

    public function nomorBerikutnya(string $idPerusahaan): string;

    public function create(array $data): object;

    public function update(object $record, array $data): object;

    public function delete(object $record): void;
}
