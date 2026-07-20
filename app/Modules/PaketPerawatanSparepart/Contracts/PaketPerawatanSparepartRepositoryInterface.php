<?php
// app/Modules/PaketPerawatanSparepart/Contracts/PaketPerawatanSparepartRepositoryInterface.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface PaketPerawatanSparepartRepositoryInterface
{
    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $idJenisPerawatan,
        ?string $idJenisKendaraan,
    ): LengthAwarePaginator;

    public function findById(string $id): ?object;

    /** findById + kolom nama relasi (jenis perawatan, jenis kendaraan, sparepart) untuk Resource. */
    public function findDetailById(string $id): ?object;

    public function findByKombinasi(
        string $idPerusahaan,
        string $idJenisPerawatan,
        string $idJenisKendaraan,
        string $idSparepart,
        ?string $excludeId = null,
    ): ?object;

    public function jenisPerawatanMilik(string $id, string $idPerusahaan): ?object;
    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object;
    public function sparepartMilik(string $id, string $idPerusahaan): ?object;

    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;

    /** Baris aktif untuk kombinasi tertentu, join sparepart untuk nama/satuan/harga — dipakai endpoint resolusi. */
    public function resolusiList(string $idPerusahaan, string $idJenisPerawatan, string $idJenisKendaraan): array;
}
