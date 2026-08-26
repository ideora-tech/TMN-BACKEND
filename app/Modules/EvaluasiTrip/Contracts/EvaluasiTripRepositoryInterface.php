<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip\Contracts;

use App\Modules\EvaluasiTrip\EvaluasiTripModel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface EvaluasiTripRepositoryInterface
{
    public function findByPenugasan(string $idPenugasan): ?EvaluasiTripModel;
    public function existsByPenugasan(string $idPenugasan): bool;
    public function findById(string $id): ?EvaluasiTripModel;
    public function create(array $data): EvaluasiTripModel;
    public function update(EvaluasiTripModel $model, array $data): EvaluasiTripModel;
    public function rekapPerVendor(string $idPerusahaan): Collection;
    public function vendorMilikPerusahaan(string $idVendor, string $idPerusahaan): bool;
    public function listByVendor(string $idVendor): Collection;

    /** Penugasan vendor yang punya minimal satu trip selesai — bahan input evaluasi, evaluasi existing ikut ter-join. */
    public function listPenugasanVendorSelesai(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator;

    /** Guard tenant: penugasan milik perusahaan (via proyek — tabel penugasan tidak punya id_perusahaan). */
    public function penugasanMilikPerusahaan(string $idPenugasan, string $idPerusahaan): bool;
}
