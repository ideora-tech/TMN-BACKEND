<?php

declare(strict_types=1);

namespace App\Modules\ParameterBok\Contracts;

use App\Modules\ParameterBok\ParameterBokModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface ParameterBokRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator;

    public function findById(string $id): ?ParameterBokModel;

    /** findById + kolom nama_jenis & nama_bbm (untuk Resource). */
    public function findDetailById(string $id): ?ParameterBokModel;

    public function findByJenisKendaraan(string $idPerusahaan, string $idJenisKendaraan, ?string $excludeId = null): ?ParameterBokModel;

    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object;

    public function create(array $data): ParameterBokModel;

    public function update(ParameterBokModel $model, array $data): ParameterBokModel;

    public function delete(ParameterBokModel $model): void;
}
