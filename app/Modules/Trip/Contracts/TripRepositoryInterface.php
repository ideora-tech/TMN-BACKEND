<?php

declare(strict_types=1);

namespace App\Modules\Trip\Contracts;

use App\Modules\Trip\TripModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface TripRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit, ?string $idJadwal = null, ?string $idPenugasan = null, ?string $idSupir = null, ?string $search = null, ?string $status = null, ?string $idProyek = null, ?string $tanggalDari = null, ?string $tanggalSampai = null, ?string $sumber = null): LengthAwarePaginator;
    public function paginateProyekSummary(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator;
    public function paginateSettlement(string $idPerusahaan, int $page, int $limit, ?string $idSupir = null, ?string $statusSettlement = null, ?string $tanggalDari = null, ?string $tanggalSampai = null, ?string $search = null): LengthAwarePaginator;
    public function exists(string $idTrip): bool;
    public function findById(string $id): ?TripModel;
    public function findByJadwal(string $idJadwal): ?TripModel;
    public function create(array $data): TripModel;
    public function update(TripModel $model, array $data): TripModel;
    public function delete(TripModel $model): void;
    public function rekapBiaya(string $idTrip): array;
    public function milikPerusahaan(string $idTrip, string $idPerusahaan): bool;
    public function findPenugasanDariTrip(string $idTrip): ?object;
    public function findPenugasanDariJadwal(string $idJadwal, string $idPerusahaan): ?object;
    public function adaTripNonFinalUntukPenugasan(string $idPenugasan, ?string $excludeTripId = null): bool;
    public function adaTripBerjalanUntukAktorLain(?string $idArmada, ?string $idSupir, ?string $idArmadaVendor, ?string $idSupirVendor, string $excludeTripId): bool;
    public function findPenugasanMilikPerusahaan(string $idPenugasan, string $idPerusahaan): ?object;
    public function findTripAktifUntukAktor(?string $idArmada, ?string $idSupir, ?string $idArmadaVendor, ?string $idSupirVendor): ?object;
    public function tripAktifSupir(string $idSupir): array;

    public function tripSelesaiPerPenugasanTanggal(array $idPenugasanList): array;
    public function namaKlienPerProyek(array $idProyekList): array;
}
