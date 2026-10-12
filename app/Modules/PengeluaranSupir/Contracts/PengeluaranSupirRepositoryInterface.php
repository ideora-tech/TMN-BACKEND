<?php

declare(strict_types=1);

namespace App\Modules\PengeluaranSupir\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface PengeluaranSupirRepositoryInterface
{
    public function paginateBySupir(string $idSupir, int $page, int $limit, ?array $statusPengajuan = null): LengthAwarePaginator;

    public function findById(string $id): ?object;

    /**
     * @param list<string> $ids
     * @return array<string, list<string>>
     */
    public function fotoUntuk(array $ids): array;

    public function kunciSupir(string $idSupir): void;

    public function kunci(string $id): void;

    public function adaKembaran(string $idSupir, string $jenis, ?string $idArmada, ?string $idArmadaVendor, string $tanggal, float $nominal, string $sejak): bool;

    public function nomorBerikutnya(string $idPerusahaan): string;

    /** @param list<string> $urlFoto */
    public function create(array $data, array $urlFoto): object;

    public function delete(object $record): void;

    /** @return list<object> */
    public function unitInternal(string $idSupir, string $idPerusahaan, ?string $idArmadaDefault, string $dari, string $sampai): array;

    /** @return list<object> */
    public function unitVendor(string $idSupir, string $idPerusahaan, string $dari, string $sampai): array;

    public function rekeningTerakhir(string $idSupir): ?object;

    public function rekeningKaryawan(string $idKaryawan, string $idPerusahaan): ?object;
}
