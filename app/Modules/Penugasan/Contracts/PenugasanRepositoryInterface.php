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
    public function listBySupirUntukProyek(string $idSupir, array $idProyekList): Collection;
    public function paginateByArmada(string $idArmada, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator;
    public function paginateBySupir(string $idSupir, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator;
    public function countSelesaiByProyek(string $idProyek): int;
    public function findById(string $id): ?PenugasanModel;
    public function hasConflict(string $idKaryawan, string $tanggalTugas, ?string $excludeId = null): bool;
    public function existsAktifUntukSupirProyek(string $idProyek, string $idSupir, ?string $excludeId = null): bool;
    public function adaKonflikAktorPadaTanggal(string $kolomAktor, string $idAktor, string $tanggalTugas, ?string $excludeId = null): bool;
    public function create(array $data): PenugasanModel;
    public function update(PenugasanModel $model, array $data): PenugasanModel;
    public function delete(PenugasanModel $model): void;
}
