<?php

declare(strict_types=1);

namespace App\Modules\Karyawan\Contracts;

use App\Modules\Karyawan\KaryawanModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface KaryawanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?KaryawanModel;
    public function findByNik(string $nik): ?KaryawanModel;
    public function create(array $data): KaryawanModel;
    public function update(KaryawanModel $model, array $data): KaryawanModel;
    public function delete(KaryawanModel $model): void;
    public function exitHistory(string $idKaryawan): array;
}
