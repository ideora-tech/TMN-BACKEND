<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface IntervalPerawatanRepositoryInterface
{
    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $idJenisKendaraan,
        ?string $search = null,
    ): LengthAwarePaginator;

    public function findById(string $id): ?object;

    /** findById + kolom nama_jenis_kendaraan (untuk Resource). */
    public function findDetailById(string $id): ?object;

    public function findByKombinasi(
        string $idPerusahaan,
        string $idJenisKendaraan,
        ?int $intervalKm,
        ?int $intervalBulan,
        ?string $excludeId = null,
    ): ?object;

    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object;

    /** Semua paket aktif untuk 1 jenis kendaraan (dipakai fitur prediksi perawatan per armada). */
    public function findAllByJenisKendaraan(string $idPerusahaan, string $idJenisKendaraan): array;

    /** Sama seperti findAllByJenisKendaraan tapi batch untuk banyak jenis kendaraan (dipakai papan-unit, hindari N+1). */
    public function findAllByJenisKendaraanIds(string $idPerusahaan, array $jenisKendaraanIds): array;

    public function create(array $data): object;

    public function update(object $record, array $data): object;

    public function delete(object $record): void;

    public function sparepartMilik(string $id, string $idPerusahaan): ?object;

    /** @return array<int, array{id_interval_perawatan:string,id_sparepart:string,nama_sparepart:string,satuan_sparepart:string,qty_standar:int}> */
    public function findSparepartByIntervalIds(array $idIntervalList): array;

    /** Replace penuh baris interval_perawatan_sparepart utk 1 interval sesuai payload terbaru. */
    public function softDeleteSparepartByInterval(string $idIntervalPerawatan): void;

    public function createSparepart(array $data): void;
}
