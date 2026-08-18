<?php

declare(strict_types=1);

namespace App\Modules\Proyek\Contracts;

use App\Modules\Proyek\ProyekModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface ProyekRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator;
    public function paginateByKlien(string $idKlien, string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator;
    public function findById(string $id): ?ProyekModel;
    public function findByKode(string $idPerusahaan, string $kode): ?ProyekModel;
    public function create(array $data): ProyekModel;
    public function update(ProyekModel $model, array $data): ProyekModel;
    public function delete(ProyekModel $model): void;
    public function getPerusahaan(string $idPerusahaan): ?object;

    /** Baris proyek terkunci (lockForUpdate) untuk guard yang butuh baca-lalu-tulis konsisten. */
    public function findByIdForUpdate(string $id): ?object;

    /** Σ total faktur proyek berstatus selain batal. */
    public function totalFakturProyek(string $idProyek): float;

    /** Baris trip selesai proyek (id_trip, id_rute, id_jenis_kendaraan efektif, id_laporan) untuk hitung total_rit & realisasi per rit. */
    public function tripSelesaiUntukRealisasi(string $idProyek): array;

    /** Σ nominal biaya_tagihan_trip untuk kumpulan id_laporan. */
    public function totalBiayaTagihanUntukLaporan(array $idLaporans): float;
}
