<?php

declare(strict_types=1);

namespace App\Modules\Penugasan\Contracts;

use App\Modules\Penugasan\PenugasanModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface PenugasanRepositoryInterface
{
    public function paginateByProyek(string $idProyek, int $page, int $limit): LengthAwarePaginator;
    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator;
    public function paginateBySupir(string $idSupir, int $page, int $limit): LengthAwarePaginator;
    public function countSelesaiByProyek(string $idProyek): int;
    public function findById(string $id): ?PenugasanModel;
    public function hasConflict(string $idKaryawan, string $tanggalTugas, ?string $excludeId = null): bool;
    public function create(array $data): PenugasanModel;
    public function update(PenugasanModel $model, array $data): PenugasanModel;
    public function delete(PenugasanModel $model): void;
}
