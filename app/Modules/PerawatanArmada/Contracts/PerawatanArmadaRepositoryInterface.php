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
    /** Nama master sparepart tanpa lock — dipakai sekadar mengisi snapshot nama baris 'bengkel' (referensi katalog, bukan pemotong stok). */
    public function getSparepartNama(string $idSparepart): ?string;

    public function supplierMilik(string $idPerusahaan, string $idSupplier): bool;
    public function getSupplierNama(string $idSupplier): ?string;
    /** @return array<string, string> nama supplier keyed by id_supplier */
    public function supplierUntukBanyak(array $idSupplierList): array;

    /** Interval (paket servis) yang dipakai banyak catatan sekaligus — hindari N+1 di list/index. @return array<string, object> keyed by id_interval_perawatan */
    public function intervalUntukBanyak(array $idIntervalList): array;

    /** Riwayat servis "selesai" terakhir per paket (id_interval_perawatan) untuk 1 armada (dipakai fitur prediksi perawatan). */
    public function getLatestPerIntervalByArmada(string $idArmada): array;
    public function kmOdometerTerakhir(string $idArmada): ?int;

    public function getLatestPerIntervalByArmadaIds(array $armadaIds): array;
    public function kmOdometerTerakhirByArmadaIds(array $armadaIds): array;
    public function getServisTerakhirSelesaiByArmadaIds(array $armadaIds): array;
    public function findArmadaPapanUnit(string $idPerusahaan, ?string $search = null): array;
}
