<?php

declare(strict_types=1);

namespace App\Modules\TarifRute\Contracts;

use App\Modules\TarifRute\TarifRuteModel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface TarifRuteRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, array $filter = []): LengthAwarePaginator;

    public function findById(string $id): ?TarifRuteModel;

    /** Sama seperti findById tapi memuat kolom nama rute/jenis/klien (untuk Resource). */
    public function findDetailById(string $id): ?TarifRuteModel;

    public function findOverlap(
        string $idPerusahaan,
        string $idRute,
        string $idJenisKendaraan,
        ?string $idKlien,
        string $tanggalMulai,
        ?string $tanggalBerakhir,
        ?string $excludeId = null,
    ): Collection;

    /** Tarif yang berlaku pada $tanggal untuk kombinasi persis (idKlien null = harga umum). */
    public function findBerlaku(
        string $idPerusahaan,
        string $idRute,
        string $idJenisKendaraan,
        ?string $idKlien,
        string $tanggal,
    ): ?TarifRuteModel;

    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object;

    public function klienMilik(string $id, string $idPerusahaan): ?object;

    public function create(array $data): TarifRuteModel;

    public function update(TarifRuteModel $model, array $data): TarifRuteModel;

    public function delete(TarifRuteModel $model): void;
}
