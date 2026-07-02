<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan\Contracts;

use App\Modules\JenisKendaraan\JenisKendaraanModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface JenisKendaraanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?JenisKendaraanModel;
    public function findByKode(string $idPerusahaan, string $kode): ?JenisKendaraanModel;
    public function create(array $data): JenisKendaraanModel;
    public function update(JenisKendaraanModel $model, array $data): JenisKendaraanModel;
    public function delete(JenisKendaraanModel $model): void;
}
