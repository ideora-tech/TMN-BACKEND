<?php

declare(strict_types=1);

namespace App\Modules\Penugasan\Contracts;

use App\Modules\Penugasan\PenugasanModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface PenugasanRepositoryInterface
{
    public function paginateByProyek(string $idProyek, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator;
    public function listJadwalSupir(string $idSupir, string $dari, string $sampai): Collection;
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator;
    public function paginateByArmada(string $idArmada, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator;
    public function paginateBySupir(string $idSupir, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator;
    public function countSelesaiByProyek(string $idProyek): int;
    public function findById(string $id): ?PenugasanModel;
    public function hasConflict(string $idKaryawan, string $tanggalTugas, ?string $excludeId = null): bool;
    public function adaKonflikAktorPadaTanggal(string $kolomAktor, string $idAktor, string $tanggalTugas, ?string $excludeId = null): bool;

    /** Guard penugasan harian: satu supir tidak boleh dobel di tanggal yang sama (lintas unit/proyek). */
    public function adaPenugasanSupirPadaTanggal(string $idSupir, string $tanggal, string $idProyek, ?string $idRute, ?string $excludeId = null): bool;

    public function create(array $data): PenugasanModel;
    public function update(PenugasanModel $model, array $data): PenugasanModel;
    public function delete(PenugasanModel $model): void;
    public function syncTitikDrop(string $idPenugasan, array $lokasiList): void;
    public function titikDropUntukBanyak(array $idPenugasan): array;

    /** Unit armada internal aktif untuk Board Unit, lengkap dengan reverse lookup supir default. */
    public function boardUnits(string $idPerusahaan): array;

    /** Baris penugasan harian dalam rentang tanggal untuk Board Unit (belum termasuk trips). */
    public function boardAssignments(string $idPerusahaan, string $dari, string $sampai): array;

    /** Trip per id_penugasan untuk Board Unit, dikelompokkan per id_penugasan. */
    public function tripsUntukPenugasanList(array $idPenugasanList): array;
}
