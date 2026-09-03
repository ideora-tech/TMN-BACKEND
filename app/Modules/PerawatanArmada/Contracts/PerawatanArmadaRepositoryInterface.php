<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PerawatanArmadaRepositoryInterface
{
    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator;
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $status, bool $jatuhTempo = false, ?string $search = null, ?string $tanggalDari = null, ?string $tanggalSampai = null): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function milikPerusahaan(string $idPerawatan, string $idPerusahaan): bool;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;

    public function getActiveLines(string $idPerawatan): array;
    public function getPerusahaan(string $idPerusahaan): ?object;
    public function rekapPerUnit(string $idPerusahaan, ?string $dari = null, ?string $sampai = null): array;
    public function listByArmadaRentang(string $idArmada, ?string $dari = null, ?string $sampai = null): array;
    public function listBukti(string $idPerawatan): array;
    public function insertBukti(array $data): void;
    public function findBukti(string $idPerawatan, string $idBukti): ?object;
    public function softDeleteBukti(string $idBukti): void;
    public function insertLine(array $data): void;
    public function softDeleteLines(string $idPerawatan): void;
    public function getSparepartForUpdate(string $idSparepart): ?object;
    public function setSparepartStok(string $idSparepart, int $stokBaru): void;
    public function insertSparepartMutasi(array $data): void;
    public function getJenisPerawatanNama(string $idJenisPerawatan): ?string;

    /** Riwayat servis "selesai" terakhir per jenis perawatan untuk 1 armada (dipakai fitur prediksi perawatan). */
    public function getLatestPerJenisByArmada(string $idArmada): array;
    public function kmOdometerTerakhir(string $idArmada): ?int;
}
